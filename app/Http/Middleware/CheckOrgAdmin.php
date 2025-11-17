<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\DB;

class CheckOrgAdmin
{
    public function handle(Request $request, Closure $next): Response
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

        $isAdmin = DB::table('organization_user')
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->where('role', 'admin')
            ->exists();

        if (!$isAdmin) {
            return response()->json(['message' => 'You must be an admin to perform this action'], 403);
        }

        return $next($request);
    }
}
