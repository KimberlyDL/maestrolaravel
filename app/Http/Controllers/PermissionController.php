<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PermissionController extends Controller
{
    /**
     * Get all available permissions grouped by category
     */
    public function index()
    {
        $permissions = Permission::all()
            ->groupBy('category')
            ->map(function ($perms) {
                return $perms->map(function ($p) {
                    return [
                        'id' => $p->id,
                        'name' => $p->name,
                        'display_name' => $p->display_name,
                        'description' => $p->description,
                        'category' => $p->category,
                    ];
                })->values();
            });

        return response()->json($permissions);
    }

    /**
     * Get all members with their permissions for an organization
     */
    public function memberPermissions(Organization $organization)
    {
        $members = $organization->members()
            ->select('users.id', 'users.name', 'users.email', 'users.avatar', 'users.avatar_url', 'organization_user.role')
            ->get()
            ->map(function ($user) use ($organization) {
                $isAdmin = in_array($user->pivot->role, ['admin', 'owner']);

                $permissions = [];
                $permissionIds = [];
                $permissionNames = [];

                if (!$isAdmin) {
                    $userPermissions = DB::table('organization_user_permissions')
                        ->join('permissions', 'organization_user_permissions.permission_id', '=', 'permissions.id')
                        ->where('organization_user_permissions.organization_id', $organization->id)
                        ->where('organization_user_permissions.user_id', $user->id)
                        ->select('permissions.id', 'permissions.name', 'permissions.display_name')
                        ->get();

                    $permissions = $userPermissions->pluck('display_name')->toArray();
                    $permissionIds = $userPermissions->pluck('id')->toArray();
                    $permissionNames = $userPermissions->pluck('name')->toArray();
                }

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar ?? $user->avatar_url,
                    'role' => $user->pivot->role,
                    'is_admin' => $isAdmin,
                    'permissions' => $permissions, // Display names for UI
                    'permission_ids' => $permissionIds, // IDs for checkboxes
                    'permission_names' => $permissionNames, // Names for backend operations
                    'permissions_count' => count($permissions),
                ];
            });

        return response()->json($members);
    }

    /**
     * Get specific user's permissions in an organization
     */
    public function userPermissions(Organization $organization, User $user)
    {
        if (!$organization->hasMember($user->id)) {
            return response()->json(['message' => 'User is not a member of this organization'], 404);
        }

        $userRole = $organization->getUserRole($user->id);

        if (in_array($userRole, ['admin', 'owner'])) {
            return response()->json([
                'user_id' => $user->id,
                'is_admin' => true,
                'granted_permissions' => [],
                'available_permissions' => []
            ]);
        }

        $grantedPermissions = DB::table('organization_user_permissions')
            ->join('permissions', 'organization_user_permissions.permission_id', '=', 'permissions.id')
            ->where('organization_user_permissions.organization_id', $organization->id)
            ->where('organization_user_permissions.user_id', $user->id)
            ->pluck('permissions.name')
            ->toArray();

        return response()->json([
            'user_id' => $user->id,
            'is_admin' => false,
            'granted_permissions' => $grantedPermissions,
        ]);
    }

    /**
     * Grant a single permission to a user
     */
    public function grantPermission(Request $request, Organization $organization, User $user)
    {
        $data = $request->validate([
            'permission_name' => 'required|string|exists:permissions,name',
        ]);

        if (!$organization->hasMember($user->id)) {
            return response()->json(['message' => 'User is not a member of this organization'], 404);
        }

        $userRole = $organization->getUserRole($user->id);
        if (in_array($userRole, ['admin', 'owner'])) {
            return response()->json(['message' => 'Cannot modify permissions for admin/owner users'], 422);
        }

        $permission = Permission::where('name', $data['permission_name'])->first();

        $exists = DB::table('organization_user_permissions')
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->where('permission_id', $permission->id)
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'User already has this permission'], 422);
        }

        DB::table('organization_user_permissions')->insert([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'permission_id' => $permission->id,
            'granted_by' => Auth::id(),
            'granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ActivityLogger::log(
            $organization->id,
            'permission_granted',
            subjectType: 'User',
            subjectId: $user->id,
            metadata: ['permission' => $permission->display_name],
            description: "Permission '{$permission->display_name}' granted to {$user->name}"
        );

        return response()->json([
            'message' => 'Permission granted successfully',
            'permission' => $permission->display_name
        ]);
    }

    /**
     * Revoke a single permission from a user
     */
    public function revokePermission(Request $request, Organization $organization, User $user)
    {
        $data = $request->validate([
            'permission_name' => 'required|string|exists:permissions,name',
        ]);

        if (!$organization->hasMember($user->id)) {
            return response()->json(['message' => 'User is not a member of this organization'], 404);
        }

        $permission = Permission::where('name', $data['permission_name'])->first();

        $deleted = DB::table('organization_user_permissions')
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->where('permission_id', $permission->id)
            ->delete();

        if (!$deleted) {
            return response()->json(['message' => 'User does not have this permission'], 422);
        }

        ActivityLogger::log(
            $organization->id,
            'permission_revoked',
            subjectType: 'User',
            subjectId: $user->id,
            metadata: ['permission' => $permission->display_name],
            description: "Permission '{$permission->display_name}' revoked from {$user->name}"
        );

        return response()->json([
            'message' => 'Permission revoked successfully',
            'permission' => $permission->display_name
        ]);
    }

    /**
     * Bulk grant permissions (replaces all existing permissions)
     */
    public function bulkGrantPermissions(Request $request, Organization $organization, User $user)
    {
        $data = $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        if (!$organization->hasMember($user->id)) {
            return response()->json(['message' => 'User is not a member of this organization'], 404);
        }

        $userRole = $organization->getUserRole($user->id);
        if (in_array($userRole, ['admin', 'owner'])) {
            return response()->json(['message' => 'Cannot modify permissions for admin/owner users'], 422);
        }

        DB::beginTransaction();

        try {
            // Remove all existing permissions
            DB::table('organization_user_permissions')
                ->where('organization_id', $organization->id)
                ->where('user_id', $user->id)
                ->delete();

            // Add new permissions
            if (!empty($data['permissions'])) {
                $permissions = Permission::whereIn('name', $data['permissions'])->get();

                $inserts = [];
                foreach ($permissions as $permission) {
                    $inserts[] = [
                        'organization_id' => $organization->id,
                        'user_id' => $user->id,
                        'permission_id' => $permission->id,
                        'granted_by' => Auth::id(),
                        'granted_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                DB::table('organization_user_permissions')->insert($inserts);
            }

            DB::commit();

            ActivityLogger::log(
                $organization->id,
                'permissions_updated',
                subjectType: 'User',
                subjectId: $user->id,
                metadata: ['permissions_count' => count($data['permissions'])],
                description: "Permissions updated for {$user->name}"
            );

            return response()->json([
                'message' => 'Permissions updated successfully',
                'permissions_count' => count($data['permissions'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to update permissions: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to update permissions'], 500);
        }
    }
}
