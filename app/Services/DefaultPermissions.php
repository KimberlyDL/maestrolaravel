<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DefaultPermissions
{
    /**
     * Default permissions for each role
     * NEW MEMBERS (non-admin) ONLY GET OVERVIEW ACCESS BY DEFAULT
     */
    public static function getDefaultPermissionsForRole(string $role): array
    {
        return match ($role) {
            'admin' => self::getAdminPermissions(),
            'member' => self::getMemberPermissions(),
            'viewer' => self::getViewerPermissions(),
            default => [],
        };
    }

    /**
     * UPDATED: Base member permissions - ONLY OVERVIEW ACCESS
     * Other tabs require explicit permission grants from admin
     */
    private static function getMemberPermissions(): array
    {
        return [
            // Only view_announcements is granted by default
            // This allows members to see the Overview tab only
            'view_announcements',
        ];
    }

    /**
     * Viewer role - read-only access (same as member by default)
     */
    private static function getViewerPermissions(): array
    {
        return [
            'view_announcements',
        ];
    }

    /**
     * Admin permissions - full access
     * Admins bypass permission checks via middleware
     */
    private static function getAdminPermissions(): array
    {
        return []; // Admins don't need explicit permissions
    }

    /**
     * Grant default permissions to a user in an organization
     * 
     * @param Organization $organization
     * @param User $user
     * @param string $role
     * @return void
     */
    public static function grantDefaultPermissions(Organization $organization, User $user, string $role): void
    {
        // Admins don't need explicit permissions (handled by middleware)
        if ($role === 'admin') {
            return;
        }

        $permissionNames = self::getDefaultPermissionsForRole($role);

        if (empty($permissionNames)) {
            return;
        }

        // Get permission IDs
        $permissions = DB::table('permissions')
            ->whereIn('name', $permissionNames)
            ->pluck('id', 'name');

        // Prepare insert data
        $inserts = [];
        foreach ($permissionNames as $permissionName) {
            if (isset($permissions[$permissionName])) {
                $inserts[] = [
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                    'permission_id' => $permissions[$permissionName],
                    'granted_by' => null, // System-granted
                    'granted_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if (!empty($inserts)) {
            DB::table('organization_user_permissions')->insert($inserts);
        }
    }

    /**
     * Update permissions when member role changes
     * 
     * @param Organization $organization
     * @param User $user
     * @param string $oldRole
     * @param string $newRole
     * @return void
     */
    public static function updatePermissionsOnRoleChange(Organization $organization, User $user, string $oldRole, string $newRole): void
    {
        // If promoting to admin, remove all explicit permissions
        if ($newRole === 'admin') {
            DB::table('organization_user_permissions')
                ->where('organization_id', $organization->id)
                ->where('user_id', $user->id)
                ->delete();
            return;
        }

        // If demoting from admin, grant default permissions for new role
        if ($oldRole === 'admin') {
            self::grantDefaultPermissions($organization, $user, $newRole);
            return;
        }

        // For other role changes, keep existing permissions
        // Don't automatically revoke or grant - let admin manage manually
    }

    /**
     * Get all available permissions that can be granted
     * Organized by category for better UX
     */
    public static function getAllPermissions(): array
    {
        return [
            'members' => [
                'view_members',
                'invite_members',
                'remove_members',
                'manage_member_roles',
                'approve_join_requests',
            ],
            'organization' => [
                'view_org_settings',
                'edit_org_profile',
                'manage_org_settings',
                'manage_invite_codes',
                'upload_org_logo',
            ],
            'announcements' => [
                'view_announcements',
                'create_announcements',
                'edit_announcements',
                'delete_announcements',
            ],
            'storage' => [
                'view_storage',
                'upload_documents',
                'create_folders',
                'delete_documents',
                'manage_document_sharing',
            ],
            'reviews' => [
                'view_reviews',
                'create_reviews',
                'manage_reviews',
                'assign_reviewers',
                'comment_on_reviews',
            ],
            'duty' => [
                'view_duty_schedules',
                'create_duty_schedules',
                'edit_duty_schedules',
                'delete_duty_schedules',
                'assign_duties',
                'approve_duty_swaps',
                'manage_duty_templates',
            ],
            'analytics' => [
                'view_statistics',
                'export_data',
                'view_activity_logs',
            ],
            'advanced' => [
                'archive_organization',
                'transfer_ownership',
                'manage_permissions',
            ],
        ];
    }
}
