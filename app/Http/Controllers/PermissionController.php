<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\ActivityLogger;

class PermissionController extends Controller
{
    /**
     * Get all available permissions (grouped by category)
     */
    public function index(Organization $organization)
    {
        return response()->json([
            'permissions' => Permission::getAllGrouped(),
        ]);
    }

    /**
     * Get all members with their permissions
     * Requires: manage_permissions
     */
    public function memberPermissions(Organization $organization)
    {
        $this->authorize('manage', $organization);

        $members = $organization->membersWithPermissions();

        return response()->json($members);
    }

    /**
     * Get specific user's permissions in organization
     * Any member can check (for UI/routing)
     */
    public function userPermissions(Organization $organization, User $user)
    {
        // Verify requesting user is a member
        if (!$organization->hasMember(Auth::id())) {
            return response()->json([
                'message' => 'You must be a member to view permissions'
            ], 403);
        }

        $role = $organization->getUserRole($user->id);

        if (!$role) {
            return response()->json([
                'message' => 'User is not a member of this organization'
            ], 404);
        }

        // Admins have all permissions
        if (in_array($role, ['admin', 'owner'])) {
            return response()->json([
                'user_id' => $user->id,
                'role' => $role,
                'is_admin' => true,
                'granted_permissions' => ['*'],
            ]);
        }

        // Get explicit permissions using Eloquent
        $permissions = $user->permissionsForOrganization($organization->id);

        return response()->json([
            'user_id' => $user->id,
            'role' => $role,
            'is_admin' => false,
            'granted_permissions' => $permissions,
        ]);
    }

    /**
     * Grant permission to user
     * Requires: manage_permissions
     */
    public function grantPermission(Request $request, Organization $organization, User $user)
    {
        $this->authorize('manage', $organization);

        $data = $request->validate([
            'permission' => 'required|string|exists:permissions,name',
        ]);

        if (!$organization->hasMember($user->id)) {
            return response()->json([
                'message' => 'User is not a member of this organization'
            ], 404);
        }

        if ($organization->isUserAdmin($user->id)) {
            return response()->json([
                'message' => 'Admins have all permissions by default'
            ], 400);
        }

        // Grant permission using Eloquent
        $success = $organization->grantPermission(
            $user->id,
            $data['permission'],
            Auth::id()
        );

        if (!$success) {
            return response()->json([
                'message' => 'Permission not found'
            ], 404);
        }

        ActivityLogger::log(
            $organization->id,
            'permission_granted',
            subjectType: 'User',
            subjectId: $user->id,
            metadata: [
                'permission' => $data['permission'],
                'granted_by' => Auth::id(),
            ],
            description: "{$user->name} was granted permission: {$data['permission']}"
        );

        return response()->json([
            'message' => 'Permission granted successfully',
            'invalidate_cache' => true, // Signal to frontend
            'affected_user_id' => $user->id,
        ]);
    }

    /**
     * Revoke permission from user
     * Requires: manage_permissions
     */
    public function revokePermission(Request $request, Organization $organization, User $user)
    {
        $this->authorize('manage', $organization);

        $data = $request->validate([
            'permission' => 'required|string|exists:permissions,name',
        ]);

        if (!$organization->hasMember($user->id)) {
            return response()->json([
                'message' => 'User is not a member of this organization'
            ], 404);
        }

        // Revoke permission using Eloquent
        $success = $organization->revokePermission($user->id, $data['permission']);

        if (!$success) {
            return response()->json([
                'message' => 'Permission not found'
            ], 404);
        }

        ActivityLogger::log(
            $organization->id,
            'permission_revoked',
            subjectType: 'User',
            subjectId: $user->id,
            metadata: [
                'permission' => $data['permission'],
                'revoked_by' => Auth::id(),
            ],
            description: "Permission revoked from {$user->name}: {$data['permission']}"
        );

        return response()->json([
            'message' => 'Permission revoked successfully',
            'invalidate_cache' => true,
            'affected_user_id' => $user->id,
        ]);
    }

    /**
     * Bulk grant/revoke permissions
     * Requires: manage_permissions
     */
    public function bulkGrantPermissions(Request $request, Organization $organization, User $user)
    {
        $this->authorize('manage', $organization);

        $data = $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        if (!$organization->hasMember($user->id)) {
            return response()->json([
                'message' => 'User is not a member of this organization'
            ], 404);
        }

        if ($organization->isUserAdmin($user->id)) {
            return response()->json([
                'message' => 'Admins have all permissions by default'
            ], 400);
        }

        // Sync permissions (replaces all existing)
        $organization->syncUserPermissions(
            $user->id,
            $data['permissions'],
            Auth::id()
        );

        ActivityLogger::log(
            $organization->id,
            'permissions_updated',
            subjectType: 'User',
            subjectId: $user->id,
            metadata: [
                'permissions' => $data['permissions'],
                'updated_by' => Auth::id(),
            ],
            description: "Permissions updated for {$user->name}"
        );

        return response()->json([
            'message' => 'Permissions updated successfully',
            'invalidate_cache' => true,
            'affected_user_id' => $user->id,
            'permissions' => $data['permissions'],
        ]);
    }
}
