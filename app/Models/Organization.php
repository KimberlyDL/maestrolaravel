<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'logo',
        'website',
        'settings',
        'invite_code',
        'mission',
        'vision',
        'auto_accept_invites',
        'public_profile',
        'member_can_invite',
        'log_retention_days',
        'is_archived',
        'archived_at',
        'location_address',
        'location_lat',
        'location_lng',
    ];

    protected $casts = [
        'settings' => 'array',
        'auto_accept_invites' => 'boolean',
        'public_profile' => 'boolean',
        'member_can_invite' => 'boolean',
        'log_retention_days' => 'integer',
        'is_archived' => 'boolean',
        'archived_at' => 'datetime',
        'location_lat' => 'decimal:7',
        'location_lng' => 'decimal:7',
    ];

    // ========================================
    // Existing Attributes/Helpers
    // ========================================

    public function getLocationAttribute()
    {
        if (!$this->location_lat || !$this->location_lng) {
            return null;
        }

        return [
            'address' => $this->location_address,
            'lat' => (float) $this->location_lat,
            'lng' => (float) $this->location_lng,
        ];
    }

    public function getLogoUrlAttribute()
    {
        if (!$this->logo) {
            return null;
        }

        if (str_starts_with($this->logo, 'http')) {
            return $this->logo;
        }

        return Storage::disk('s3')->url($this->logo);
    }

    // ========================================
    // Member Relationships (Your Existing)
    // ========================================

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function users(): BelongsToMany
    {
        return $this->members();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationUser::class);
    }

    public function admins(): BelongsToMany
    {
        return $this->members()->wherePivot('role', 'admin');
    }

    // ========================================
    // Permission Relationships (NEW - Eloquent)
    // ========================================

    /**
     * Get permissions for a specific user in this organization
     */
    public function userPermissions(int $userId): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'organization_user_permissions')
            ->wherePivot('user_id', $userId)
            ->withPivot('user_id', 'granted_by', 'granted_at')
            ->withTimestamps();
    }

    /**
     * Get all members with their permissions
     */
    public function membersWithPermissions()
    {
        return $this->members()->get()->map(function ($member) {
            $role = $member->pivot->role;

            // Admins have all permissions
            if (in_array($role, ['admin', 'owner'])) {
                return [
                    'id' => $member->id,
                    'user_id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'avatar' => $member->avatar ?? $member->avatar_url,
                    'role' => $role,
                    'permissions' => ['*'], // All permissions marker
                    'is_admin' => true,
                ];
            }

            // Get explicit permissions for regular members
            $permissions = $this->userPermissions($member->id)
                ->pluck('permissions.name')
                ->toArray();

            return [
                'id' => $member->id,
                'user_id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'avatar' => $member->avatar ?? $member->avatar_url,
                'role' => $role,
                'permissions' => $permissions,
                'is_admin' => false,
            ];
        });
    }

    /**
     * Grant permission to user
     */
    public function grantPermission(int $userId, string $permissionName, ?int $grantedBy = null): bool
    {
        $permission = Permission::where('name', $permissionName)->first();

        if (!$permission) {
            return false;
        }

        // Use syncWithoutDetaching to avoid duplicates
        $this->userPermissions($userId)->syncWithoutDetaching([
            $permission->id => [
                'granted_by' => $grantedBy ?? auth()->id(),
                'granted_at' => now(),
            ]
        ]);

        return true;
    }

    /**
     * Revoke permission from user
     */
    public function revokePermission(int $userId, string $permissionName): bool
    {
        $permission = Permission::where('name', $permissionName)->first();

        if (!$permission) {
            return false;
        }

        // Manual delete because we need to filter by organization_id
        \DB::table('organization_user_permissions')
            ->where('organization_id', $this->id)
            ->where('user_id', $userId)
            ->where('permission_id', $permission->id)
            ->delete();

        return true;
    }

    /**
     * Sync user permissions (replaces all permissions)
     */
    public function syncUserPermissions(int $userId, array $permissionNames, ?int $grantedBy = null): void
    {
        $permissions = Permission::whereIn('name', $permissionNames)->get();

        // Remove existing permissions for this user in this org
        \DB::table('organization_user_permissions')
            ->where('organization_id', $this->id)
            ->where('user_id', $userId)
            ->delete();

        // Add new permissions
        $inserts = $permissions->map(function ($permission) use ($userId, $grantedBy) {
            return [
                'organization_id' => $this->id,
                'user_id' => $userId,
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
    // Member Helper Methods (Your Existing)
    // ========================================

    public function hasMember(int $userId): bool
    {
        return $this->members()->where('users.id', $userId)->exists();
    }

    public function getUserRole(int $userId): ?string
    {
        return $this->members()
            ->where('users.id', $userId)
            ->first()?->pivot?->role;
    }

    public function isUserAdmin(int $userId): bool
    {
        $role = $this->getUserRole($userId);
        return in_array($role, ['admin', 'owner']);
    }

    // ========================================
    // Other Relationships (Your Existing)
    // ========================================

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function reviewRequests(): HasMany
    {
        return $this->hasMany(ReviewRequest::class, 'publisher_org_id');
    }

    // ========================================
    // Query Scopes (Your Existing)
    // ========================================

    public function scopeActive($query)
    {
        return $query->where('is_archived', false);
    }

    public function scopeArchived($query)
    {
        return $query->where('is_archived', true);
    }
}
