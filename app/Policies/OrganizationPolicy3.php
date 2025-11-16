<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Organization;

class OrganizationPolicy
{
    /**
     * Determine if user can view organization members
     */
    public function viewMembers(User $user, Organization $organization): bool
    {
        return $organization->hasMember($user->id);
    }

    /**
     * Determine if user can view the organization
     */
    public function view(User $user, Organization $organization): bool
    {
        return $organization->hasMember($user->id);
    }

    /**
     * Determine if user can manage the organization (admin or has permission)
     */
    public function manage(User $user, Organization $organization): bool
    {
        return $user->hasOrgPermission($organization->id, 'manage_settings');
    }

    /**
     * Manage members (add, remove, change roles)
     */
    public function manageMembers(User $user, Organization $organization): bool
    {
        return $user->hasOrgPermission($organization->id, 'manage_members');
    }

    /**
     * Approve join requests
     */
    public function approveJoinRequests(User $user, Organization $organization): bool
    {
        return $user->hasOrgPermission($organization->id, 'approve_join_requests');
    }

    /**
     * Manage invite codes
     */
    public function manageInvites(User $user, Organization $organization): bool
    {
        return $user->hasOrgPermission($organization->id, 'manage_invites');
    }

    /**
     * View duty schedules
     */
    public function viewDutySchedules(User $user, Organization $organization): bool
    {
        return $organization->hasMember($user->id);
    }

    /**
     * Manage duty schedules
     */
    public function manageDutySchedules(User $user, Organization $organization): bool
    {
        return $user->hasOrgPermission($organization->id, 'manage_duty_schedules');
    }

    /**
     * Assign officers to duty
     */
    public function assignDutyOfficers(User $user, Organization $organization): bool
    {
        return $user->hasOrgPermission($organization->id, 'assign_duty_officers');
    }

    /**
     * Approve duty swaps
     */
    public function approveDutySwaps(User $user, Organization $organization): bool
    {
        return $user->hasOrgPermission($organization->id, 'approve_duty_swaps');
    }

    /**
     * Create announcements
     */
    public function createAnnouncements(User $user, Organization $organization): bool
    {
        return $user->hasOrgPermission($organization->id, 'create_announcements');
    }

    /**
     * Manage announcements (edit/delete any)
     */
    public function manageAnnouncements(User $user, Organization $organization): bool
    {
        return $user->hasOrgPermission($organization->id, 'manage_announcements');
    }

    /**
     * View statistics
     */
    public function viewStatistics(User $user, Organization $organization): bool
    {
        return $user->hasOrgPermission($organization->id, 'view_statistics');
    }
}
