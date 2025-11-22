<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\DB;
use App\Models\Organization;

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

        // Ensure we have Organization model instance
        if (!$organization instanceof Organization) {
            $organization = Organization::find($organization);

            if (!$organization) {
                return response()->json(['message' => 'Organization not found'], 404);
            }
        }

        // Check if user is a member using Eloquent
        if (!$organization->hasMember($user->id)) {
            return response()->json([
                'message' => 'You are not a member of this organization'
            ], 403);
        }

        // Get user's role using Eloquent
        $userRole = $organization->getUserRole($user->id);

        // RULE 1: Admins have ALL permissions
        if (in_array($userRole, ['admin', 'owner'])) {
            return $next($request);
        }

        // RULE 2: Check implicit member permissions
        if (in_array($permission, self::IMPLICIT_MEMBER_PERMISSIONS)) {
            return $next($request);
        }

        // RULE 3: Check explicit permissions using Eloquent
        $hasPermission = $user->hasPermission($organization->id, $permission);

        if (!$hasPermission) {
            return response()->json([
                'message' => 'You do not have permission to perform this action',
                'required_permission' => $permission,
                'your_role' => $userRole,
            ], 403);
        }

        return $next($request);
    }
}
