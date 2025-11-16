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
}
