<?php

namespace App\Traits;

use Illuminate\Support\Facades\DB;

trait HasOrganizationPermissions
{
    /**
     * Check if user has a specific permission in an organization
     */
    public function hasOrgPermission(int $organizationId, string $permission): bool
    {
        // Check if user is admin in the organization (admins have all permissions)
        $role = DB::table('organization_user')
            ->where('organization_id', $organizationId)
            ->where('user_id', $this->id)
            ->value('role');

        if ($role === 'admin') {
            return true;
        }

        // Check if user has the specific permission
        return DB::table('organization_user_permissions as oup')
            ->join('permissions as p', 'oup.permission_id', '=', 'p.id')
            ->where('oup.organization_id', $organizationId)
            ->where('oup.user_id', $this->id)
            ->where('p.name', $permission)
            ->exists();
    }

    /**
     * Check if user has any of the given permissions
     */
    public function hasAnyOrgPermission(int $organizationId, array $permissions): bool
    {
        $role = DB::table('organization_user')
            ->where('organization_id', $organizationId)
            ->where('user_id', $this->id)
            ->value('role');

        if ($role === 'admin') {
            return true;
        }

        return DB::table('organization_user_permissions as oup')
            ->join('permissions as p', 'oup.permission_id', '=', 'p.id')
            ->where('oup.organization_id', $organizationId)
            ->where('oup.user_id', $this->id)
            ->whereIn('p.name', $permissions)
            ->exists();
    }

    /**
     * Check if user has all of the given permissions
     */
    public function hasAllOrgPermissions(int $organizationId, array $permissions): bool
    {
        $role = DB::table('organization_user')
            ->where('organization_id', $organizationId)
            ->where('user_id', $this->id)
            ->value('role');

        if ($role === 'admin') {
            return true;
        }

        $grantedCount = DB::table('organization_user_permissions as oup')
            ->join('permissions as p', 'oup.permission_id', '=', 'p.id')
            ->where('oup.organization_id', $organizationId)
            ->where('oup.user_id', $this->id)
            ->whereIn('p.name', $permissions)
            ->count();

        return $grantedCount === count($permissions);
    }

    /**
     * Get all permissions user has in an organization
     */
    public function getOrgPermissions(int $organizationId): array
    {
        $role = DB::table('organization_user')
            ->where('organization_id', $organizationId)
            ->where('user_id', $this->id)
            ->value('role');

        // Admins have all permissions
        if ($role === 'admin') {
            return DB::table('permissions')->pluck('name')->toArray();
        }

        return DB::table('organization_user_permissions as oup')
            ->join('permissions as p', 'oup.permission_id', '=', 'p.id')
            ->where('oup.organization_id', $organizationId)
            ->where('oup.user_id', $this->id)
            ->pluck('p.name')
            ->toArray();
    }

    /**
     * Check if user is admin in organization
     */
    public function isOrgAdmin(int $organizationId): bool
    {
        return DB::table('organization_user')
            ->where('organization_id', $organizationId)
            ->where('user_id', $this->id)
            ->where('role', 'admin')
            ->exists();
    }
}
