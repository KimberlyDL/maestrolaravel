<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckOrganizationPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        $organization = $request->route('organization');

        if (!$organization) {
            return response()->json([
                'message' => 'Organization not found in route'
            ], 404);
        }

        // Get organization ID
        $orgId = is_object($organization) ? $organization->id : $organization;

        // Check if user is admin (admins have all permissions)
        $role = \DB::table('organization_user')
            ->where('organization_id', $orgId)
            ->where('user_id', $user->id)
            ->value('role');

        if ($role === 'admin') {
            return $next($request);
        }

        // Check if user has the specific permission
        $hasPermission = \DB::table('organization_user_permissions as oup')
            ->join('permissions as p', 'oup.permission_id', '=', 'p.id')
            ->where('oup.organization_id', $orgId)
            ->where('oup.user_id', $user->id)
            ->where('p.name', $permission)
            ->exists();

        if (!$hasPermission) {
            return response()->json([
                'message' => 'You do not have permission to perform this action',
                'required_permission' => $permission
            ], 403);
        }

        return $next($request);
    }
}
