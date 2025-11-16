<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureEmailIsVerifiedApi
{
    public function handle(Request $request, Closure $next)
    {
        $user = auth('api')->user();

        if (! $user || ! $user->hasVerifiedEmail()) {
            return response()->json([
                'code'    => 'EMAIL_NOT_VERIFIED',
                'message' => 'Please verify your email to access this resource.'
            ], 403);
        }

        return $next($request);
    }
}
