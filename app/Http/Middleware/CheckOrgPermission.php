<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\DB;

class CheckOrgPermission
{
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

        $isMember = DB::table('organization_user')
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->exists();

        if (!$isMember) {
            return response()->json(['message' => 'You are not a member of this organization'], 403);
        }

        $userRole = DB::table('organization_user')
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->value('role');

        if ($userRole === 'admin') {
            return $next($request);
        }

        $hasPermission = DB::table('organization_user_permissions')
            ->join('permissions', 'organization_user_permissions.permission_id', '=', 'permissions.id')
            ->where('organization_user_permissions.organization_id', $organizationId)
            ->where('organization_user_permissions.user_id', $user->id)
            ->where('permissions.name', $permission)
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
