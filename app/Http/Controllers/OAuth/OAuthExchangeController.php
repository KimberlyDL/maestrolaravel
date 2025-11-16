<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tymon\JWTAuth\Facades\JWTAuth;

class OAuthExchangeController extends Controller
{
    /**
     * Exchange one-time OAuth code for JWT token
     */
    public function exchange(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $code = $request->input('code');

        // Retrieve user ID from cache
        $userId = Cache::get("oauth_code:{$code}");

        if (!$userId) {
            return response()->json([
                'error' => 'Invalid or expired code'
            ], 401);
        }

        // Delete the code immediately (one-time use)
        Cache::forget("oauth_code:{$code}");

        // Find the user
        $user = User::find($userId);

        if (!$user) {
            return response()->json([
                'error' => 'User not found'
            ], 404);
        }

        // Generate JWT token
        $token = JWTAuth::fromUser($user);

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => config('jwt.ttl') * 60, // convert minutes to seconds
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar,
                'email_verified_at' => $user->email_verified_at,
            ]
        ]);
    }
}
