<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;          // <-- add this import
use Illuminate\Support\Facades\Cache;

class OAuthExchangeController extends Controller
{
    public function exchange(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required','string','min:20'],
        ]);

        // No named params; keep it simple
        $cacheKey = "oauth_code:{$request->code}";
        $userId   = Cache::pull($cacheKey); // one-time fetch + delete

        if (!$userId) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        $user = User::find($userId);
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        // Make sure your 'api' guard is JWT
        $token = auth('api')->login($user);

        return response()->json([
            'message'    => 'OAuth exchange successful',
            'token'      => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user'       => $user,
        ]);
    }
}
