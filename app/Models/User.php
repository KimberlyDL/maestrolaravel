<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\HasOrganizationPermissions;

class User extends Authenticatable implements MustVerifyEmail, JWTSubject
{
    use HasFactory, Notifiable, HasOrganizationPermissions;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'address',
        'city',
        'country',
        'bio',
        'avatar_path',
        'avatar_url',
        'role',
        'provider',
        'provider_id',
        'avatar',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // JWT methods
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [
            'role' => $this->role,
            'email_verified' => !is_null($this->email_verified_at),
        ];
    }

    // OAuth check
    public function isOAuthUser(): bool
    {
        return !empty($this->provider);
    }

    // Avatar URL
    public function getAvatarAttribute($value)
    {
        if ($value) return $value;
        if ($this->avatar_url) return $this->avatar_url;
        if ($this->avatar_path) return asset('storage/' . $this->avatar_path);
        return 'https://ui-avatars.com/api/?name=' . urlencode($this->name) . '&background=random';
    }

    // ========================================
    // Organization Relationships
    // ========================================

    /**
     * Organizations this user belongs to (with role)
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Direct access to pivot records
     */
    public function orgMemberships(): HasMany
    {
        return $this->hasMany(OrganizationUser::class);
    }

    // ========================================
    // Permission Relationships (NEW - Eloquent)
    // ========================================

    /**
     * Get permissions for a specific organization
     * Returns Permission models with pivot data
     */
    public function organizationPermissions(int $organizationId): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'organization_user_permissions')
            ->withPivot('organization_id', 'granted_by', 'granted_at')
            ->wherePivot('organization_id', $organizationId)
            ->withTimestamps();
    }

    /**
     * Get all permissions across all organizations
     */
    public function allOrganizationPermissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'organization_user_permissions')
            ->withPivot('organization_id', 'granted_by', 'granted_at')
            ->withTimestamps();
    }

    // ========================================
    // Permission Check Methods (Eloquent-based)
    // ========================================

    /**
     * Get permissions for organization (returns array of names)
     */
    public function permissionsForOrganization(int $organizationId): array
    {
        return $this->organizationPermissions($organizationId)
            ->pluck('permissions.name')
            ->toArray();
    }

    /**
     * Check if user has specific permission in organization
     */
    public function hasPermission(int $organizationId, string $permission): bool
    {
        // Admins have all permissions
        $role = $this->roleInOrg($organizationId);
        if (in_array($role, ['admin', 'owner'])) {
            return true;
        }

        // Check explicit permission using Eloquent
        return $this->organizationPermissions($organizationId)
            ->where('permissions.name', $permission)
            ->exists();
    }

    /**
     * Check if user has ANY of the given permissions
     */
    public function hasAnyPermission(int $organizationId, array $permissions): bool
    {
        $role = $this->roleInOrg($organizationId);
        if (in_array($role, ['admin', 'owner'])) {
            return true;
        }

        return $this->organizationPermissions($organizationId)
            ->whereIn('permissions.name', $permissions)
            ->exists();
    }

    /**
     * Check if user has ALL of the given permissions
     */
    public function hasAllPermissions(int $organizationId, array $permissions): bool
    {
        $role = $this->roleInOrg($organizationId);
        if (in_array($role, ['admin', 'owner'])) {
            return true;
        }

        $count = $this->organizationPermissions($organizationId)
            ->whereIn('permissions.name', $permissions)
            ->count();

        return $count === count($permissions);
    }

    /**
     * Grant permission to user in organization
     */
    public function grantPermission(int $organizationId, string $permissionName, ?int $grantedBy = null): bool
    {
        $permission = Permission::where('name', $permissionName)->first();

        if (!$permission) {
            return false;
        }

        // Use sync to avoid duplicates
        $this->organizationPermissions($organizationId)->syncWithoutDetaching([
            $permission->id => [
                'granted_by' => $grantedBy ?? auth()->id(),
                'granted_at' => now(),
            ]
        ]);

        return true;
    }

    /**
     * Revoke permission from user in organization
     */
    public function revokePermission(int $organizationId, string $permissionName): bool
    {
        $permission = Permission::where('name', $permissionName)->first();

        if (!$permission) {
            return false;
        }

        // Need to manually delete because we can't detach with wherePivot
        \DB::table('organization_user_permissions')
            ->where('organization_id', $organizationId)
            ->where('user_id', $this->id)
            ->where('permission_id', $permission->id)
            ->delete();

        return true;
    }

    /**
     * Sync permissions for user in organization (replaces all)
     */
    public function syncPermissions(int $organizationId, array $permissionNames, ?int $grantedBy = null): void
    {
        $permissions = Permission::whereIn('name', $permissionNames)->get();

        // First, remove all permissions for this org
        \DB::table('organization_user_permissions')
            ->where('organization_id', $organizationId)
            ->where('user_id', $this->id)
            ->delete();

        // Then add new permissions
        $inserts = $permissions->map(function ($permission) use ($organizationId, $grantedBy) {
            return [
                'organization_id' => $organizationId,
                'user_id' => $this->id,
                'permission_id' => $permission->id,
                'granted_by' => $grantedBy ?? auth()->id(),
                'granted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        })->toArray();

        if (!empty($inserts)) {
            \DB::table('organization_user_permissions')->insert($inserts);
        }
    }

    // ========================================
    // Organization Helper Methods (Your Existing)
    // ========================================

    public function roleInOrg($organizationId): ?string
    {
        $m = $this->orgMemberships()->where('organization_id', $organizationId)->first();
        return $m?->role;
    }

    public function isInOrg($organizationId): bool
    {
        return $this->organizations()->where('organizations.id', $organizationId)->exists();
    }

    public function isOrgRole($organizationId, array|string $roles): bool
    {
        $roles = (array) $roles;
        $pivot = $this->orgMemberships()->where('organization_id', $organizationId)->first();
        return $pivot && in_array($pivot->role, $roles, true);
    }

    // ========================================
    // Document/Review Relationships (Your Existing)
    // ========================================

    public function createdDocuments()
    {
        return $this->hasMany(Document::class, 'created_by');
    }

    public function uploadedVersions()
    {
        return $this->hasMany(DocumentVersion::class, 'uploaded_by');
    }

    public function submittedReviewRequests()
    {
        return $this->hasMany(ReviewRequest::class, 'submitted_by');
    }

    public function reviewAssignments()
    {
        return $this->hasMany(ReviewRecipient::class, 'reviewer_user_id');
    }

    public function reviewComments()
    {
        return $this->hasMany(ReviewComment::class, 'author_user_id');
    }

    public function reviewActions()
    {
        return $this->hasMany(ReviewAction::class, 'actor_user_id');
    }

    public function reviewAttachments()
    {
        return $this->hasMany(ReviewAttachment::class, 'uploaded_by');
    }
}
