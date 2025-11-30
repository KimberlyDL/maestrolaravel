<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Organization;

class OrganizationPolicy
{
    /**
     * Create a new policy instance.
     */
    public function __construct()
    {
        //
    }


    /**
     * Determine if user can view organization members
     */
    public function viewMembers(User $user, Organization $organization): bool
    {
        // User must be a member of the organization to view its members
        // return $organization->hasMember($user->id);

        return true; // Temporarily allow all; restrict later if needed
    }

    /**
     * Determine if user can view the organization
     */
    public function view(User $user, Organization $organization): bool
    {
        return $organization->hasMember($user->id);
    }

    /**
     * Determine if user can manage the organization (admin only)
     */
    public function manage(User $user, Organization $organization): bool
    {
        return $organization->getUserRole($user->id) === 'admin';
    }


    public function viewDutySchedules(User $user, Organization $organization): bool
    {
        return $organization->hasMember($user->id);
    }

    public function manageDutySchedules(User $user, Organization $organization): bool
    {
        $role = $organization->getUserRole($user->id);
        return in_array($role, ['admin', 'owner']);
    }

    /* ==================== GRANULAR PERMISSIONS ==================== */

    public function managePermissions(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization->id, 'manage_permissions');
    }

    public function editProfile(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization->id, 'edit_org_profile');
    }

    public function manageSettings(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization->id, 'manage_org_settings');
    }

    public function uploadLogo(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization->id, 'upload_org_logo');
    }

    public function manageInviteCodes(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization->id, 'manage_invite_codes');
    }

    public function approveJoinRequests(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization->id, 'approve_join_requests');
    }

    public function manageMemberRoles(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization->id, 'manage_member_roles');
    }

    public function removeMembers(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization->id, 'remove_members');
    }

    public function createAnnouncements(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization->id, 'create_announcements');
    }

    public function editAnnouncements(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization->id, 'edit_announcements');
    }

    public function deleteAnnouncements(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization->id, 'delete_announcements');
    }

    public function viewStatistics(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization->id, 'view_statistics');
    }

    public function viewActivityLogs(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization->id, 'view_activity_logs');
    }

    public function exportData(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization->id, 'export_data');
    }

    public function archive(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization->id, 'archive_organization');
    }

    public function transferOwnership(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization->id, 'transfer_ownership');
    }
}
