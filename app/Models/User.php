<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Support\Facades\DB;
use App\Models\Permission;
use App\Traits\HasOrganizationPermissions;

class User extends Authenticatable implements MustVerifyEmail, JWTSubject
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasOrganizationPermissions;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
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
        // OAuth fields (for Google/social login)
        'provider',       // 'google', 'github', etc.
        'provider_id',    // OAuth provider's user ID
        'avatar',         // OAuth avatar URL (can merge with avatar_url if needed)
        'email_verified_at', // Add this to fillable for OAuth auto-verification
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the identifier that will be stored in the JWT subject claim.
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to JWT.
     */
    public function getJWTCustomClaims(): array
    {
        return [
            'role' => $this->role,
            'email_verified' => !is_null($this->email_verified_at),
        ];
    }

    /**
     * Check if user signed up via OAuth provider
     */
    public function isOAuthUser(): bool
    {
        return !empty($this->provider);
    }

    /**
     * Get the user's avatar URL (prefer OAuth avatar, fallback to local)
     */
    public function getAvatarAttribute($value)
    {
        // If OAuth avatar exists, use it
        if ($value) {
            return $value;
        }

        // Otherwise use local avatar
        if ($this->avatar_url) {
            return $this->avatar_url;
        }

        if ($this->avatar_path) {
            return asset('storage/' . $this->avatar_path);
        }

        // Default avatar
        return 'https://ui-avatars.com/api/?name=' . urlencode($this->name) . '&background=random';
    }

    /** Multi-org membership */
    public function organizations()
    {
        return $this->belongsToMany(Organization::class, 'organization_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /** Optional: pivot records directly */
    public function orgMemberships()
    {
        return $this->hasMany(OrganizationUser::class);
    }

    /** Documents created by this user (owner/author) */
    public function createdDocuments()
    {
        return $this->hasMany(Document::class, 'created_by');
    }

    /** Versions uploaded by this user */
    public function uploadedVersions()
    {
        return $this->hasMany(DocumentVersion::class, 'uploaded_by');
    }

    /** Review requests submitted by this user */
    public function submittedReviewRequests()
    {
        return $this->hasMany(ReviewRequest::class, 'submitted_by');
    }

    /** Reviewer assignments (per-reviewer state) */
    public function reviewAssignments()
    {
        return $this->hasMany(ReviewRecipient::class, 'reviewer_user_id');
    }

    /** Comments authored by this user */
    public function reviewComments()
    {
        return $this->hasMany(ReviewComment::class, 'author_user_id');
    }

    /** Actions (audit log) performed by this user */
    public function reviewActions()
    {
        return $this->hasMany(ReviewAction::class, 'actor_user_id');
    }

    /** Attachments uploaded by this user */
    public function reviewAttachments()
    {
        return $this->hasMany(ReviewAttachment::class, 'uploaded_by');
    }

    /** ---- Convenience helpers (optional) ---- */
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







    /**
     * Get user's permissions for a specific organization
     */
    public function permissionsForOrganization(int $organizationId): array
    {
        return DB::table('organization_user_permissions')
            ->join('permissions', 'organization_user_permissions.permission_id', '=', 'permissions.id')
            ->where('organization_user_permissions.organization_id', $organizationId)
            ->where('organization_user_permissions.user_id', $this->id)
            ->pluck('permissions.name')
            ->toArray();
    }

    /**
     * Check if user has a specific permission in an organization
     */
    public function hasPermission(int $organizationId, string $permission): bool
    {
        // Admins have all permissions
        $role = $this->roleInOrg($organizationId);
        if (in_array($role, ['admin', 'owner'])) {
            return true;
        }

        return DB::table('organization_user_permissions')
            ->join('permissions', 'organization_user_permissions.permission_id', '=', 'permissions.id')
            ->where('organization_user_permissions.organization_id', $organizationId)
            ->where('organization_user_permissions.user_id', $this->id)
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

        return DB::table('organization_user_permissions')
            ->join('permissions', 'organization_user_permissions.permission_id', '=', 'permissions.id')
            ->where('organization_user_permissions.organization_id', $organizationId)
            ->where('organization_user_permissions.user_id', $this->id)
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

        $count = DB::table('organization_user_permissions')
            ->join('permissions', 'organization_user_permissions.permission_id', '=', 'permissions.id')
            ->where('organization_user_permissions.organization_id', $organizationId)
            ->where('organization_user_permissions.user_id', $this->id)
            ->whereIn('permissions.name', $permissions)
            ->count();

        return $count === count($permissions);
    }

    /**
     * Grant permission to user in organization
     */
    public function grantPermission(int $organizationId, string $permissionName): void
    {
        $permission = Permission::where('name', $permissionName)->firstOrFail();

        DB::table('organization_user_permissions')->insertOrIgnore([
            'organization_id' => $organizationId,
            'user_id' => $this->id,
            'permission_id' => $permission->id,
            'granted_by' => auth()->id(),
            'granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Revoke permission from user in organization
     */
    public function revokePermission(int $organizationId, string $permissionName): void
    {
        $permission = Permission::where('name', $permissionName)->first();
        if (!$permission) return;

        DB::table('organization_user_permissions')
            ->where('organization_id', $organizationId)
            ->where('user_id', $this->id)
            ->where('permission_id', $permission->id)
            ->delete();
    }

    /**
     * Sync permissions for user in organization (replaces all)
     */
    public function syncPermissions(int $organizationId, array $permissionNames): void
    {
        DB::transaction(function () use ($organizationId, $permissionNames) {
            // Remove existing permissions
            DB::table('organization_user_permissions')
                ->where('organization_id', $organizationId)
                ->where('user_id', $this->id)
                ->delete();

            // Add new permissions
            foreach ($permissionNames as $permissionName) {
                $this->grantPermission($organizationId, $permissionName);
            }
        });
    }
}
