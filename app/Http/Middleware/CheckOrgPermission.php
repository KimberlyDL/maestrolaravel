<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\DB;

class CheckOrgPermission
{
    /**
     * Permissions that ALL members have by default (no database check needed)
     * These are core features every organization member should access
     */
    private const IMPLICIT_MEMBER_PERMISSIONS = [
        // Member self-service actions (always allowed for any member)
        'view_own_assignments',
        'manage_own_availability',
        'request_duty_swap',
        'check_in_duty',
        'check_out_duty',
        'respond_to_assignment',
        'leave_organization',
        'view_own_statistics',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  $permission  The permission name to check
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $organization = $request->route('organization');

        if (!$organization) {
            return response()->json(['message' => 'Organization not found in route'], 400);
        }

        $organizationId = is_object($organization) ? $organization->id : $organization;

        // Check if user is a member
        $membership = DB::table('organization_user')
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->first();

        if (!$membership) {
            return response()->json([
                'message' => 'You are not a member of this organization'
            ], 403);
        }

        $userRole = $membership->role;

        // RULE 1: Admins have ALL permissions automatically
        if (in_array($userRole, ['admin', 'owner'])) {
            return $next($request);
        }

        // RULE 2: Check for implicit member permissions (no database lookup needed)
        if (in_array($permission, self::IMPLICIT_MEMBER_PERMISSIONS)) {
            return $next($request);
        }

        // RULE 3: Check explicit permissions in database
        $hasPermission = DB::table('organization_user_permissions')
            ->join('permissions', 'organization_user_permissions.permission_id', '=', 'permissions.id')
            ->where('organization_user_permissions.organization_id', $organizationId)
            ->where('organization_user_permissions.user_id', $user->id)
            ->where('permissions.name', $permission)
            ->exists();

        if (!$hasPermission) {
            return response()->json([
                'message' => 'You do not have permission to perform this action',
                'required_permission' => $permission,
                'your_role' => $userRole,
            ], 403);
        }

        return $next($request);
    }

    /**
     * Check if a user has a specific permission (static helper method)
     * 
     * @param int $organizationId
     * @param int $userId
     * @param string $permission
     * @return bool
     */
    public static function userHasPermission(int $organizationId, int $userId, string $permission): bool
    {
        // Get user role
        $userRole = DB::table('organization_user')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->value('role');

        if (!$userRole) {
            return false;
        }

        // Admins have all permissions
        if (in_array($userRole, ['admin', 'owner'])) {
            return true;
        }

        // Check implicit member permissions
        if (in_array($permission, self::IMPLICIT_MEMBER_PERMISSIONS)) {
            return true;
        }

        // Check database
        return DB::table('organization_user_permissions')
            ->join('permissions', 'organization_user_permissions.permission_id', '=', 'permissions.id')
            ->where('organization_user_permissions.organization_id', $organizationId)
            ->where('organization_user_permissions.user_id', $userId)
            ->where('permissions.name', $permission)
            ->exists();
    }
}
