<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Hash;
use App\Models\OrganizationUser;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc,dns', 'max:255', 'unique:users,email'],
            'password' => [
                'required',
                'confirmed',
                Password::min(8)->mixedCase()->numbers()->uncompromised()
            ],
        ], [], [
            'email' => 'email address',
        ]);

        $user = User::create([
            'email'    => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $user->sendEmailVerificationNotification();

        return response()->json([
            'message' => 'Account created. Please verify your email.',
            'email'   => $user->email,
        ], 201);
    }


    public function login(Request $request)
    {

        $creds = $request->only('email', 'password');

        if (! $user = \App\Models\User::where('email', $creds['email'])->first()) {
            return response()->json(['message' => 'Invalid email or password.'], 401);
        }

        if (! \Illuminate\Support\Facades\Hash::check($creds['password'], $user->password)) {
            return response()->json(['message' => 'Invalid email or password.'], 401);
        }

        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'code'    => 'EMAIL_NOT_VERIFIED',
                'message' => 'Please verify your email to continue.',
                'unverified' => true,
            ], 403);
        }

        if (! $token = auth('api')->attempt($creds)) {
            return response()->json(['code' => 'INVALID_CREDENTIALS'], 401);
        }

        return $this->respondWithToken($token, 'login');
    }

    /**
     * Get the authenticated user with organizations (via existing pivot)
     */
    public function me(Request $request)
    {
        $user = $request->user();

        // Pull memberships from the pivot, eager-load the organization
        $memberships = OrganizationUser::query()
            ->where('user_id', $user->id)
            ->with(['organization:id,name,slug,description'])
            ->get();

        // Shape the org list from the pivot + eager-loaded org
        $organizations = $memberships->map(function ($m) {
            return [
                'id'          => $m->organization->id,
                'name'        => $m->organization->name,
                'slug'        => $m->organization->slug,
                'description' => $m->organization->description,
                'role'        => $m->role,          // from pivot
            ];
        });

        return response()->json([
            'id'                => $user->id,
            'name'              => $user->name,
            'email'             => $user->email,
            'avatar'            => $user->avatar ?? $user->avatar_url,
            'email_verified_at' => $user->email_verified_at,
            'role'              => $user->role ?? null,
            'organizations'     => $organizations,
        ]);
    }

    public function refresh()
    {
        return $this->respondWithToken(auth('api')->refresh(), 'refresh');
    }

    public function logout()
    {
        auth('api')->logout();
        return response()->json(['message' => 'Logged out']);
    }

    protected function respondWithToken(string $token, string $context = 'refresh')
    {
        return response()->json([
            'message'    => 'Login successful',
            'token'      => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'issued_from' => $context,
            'user'       => auth('api')->user(),
        ]);
    }
}
