<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    /**
     * Return Google auth URL for SPA to redirect to.
     */
    public function redirectToGoogle(Request $request)
    {
        // Stateless for SPA/API (no session)
        $url = Socialite::driver('google')
            ->stateless()
            ->redirect()
            ->getTargetUrl();

        return response()->json(['authUrl' => $url]);
    }

    /**
     * Google redirects here (your GOOGLE_REDIRECT_URI).
     * We create/fetch the local user, mint a short-lived code, then
     * redirect back to the SPA with #code=... (SPA will call /api/oauth/exchange).
     */
    public function handleGoogleCallback(Request $request): RedirectResponse
    {
        $frontend = config('app.frontend_url', 'http://localhost:5173');

        try {
            $googleUser = Socialite::driver('google')->stateless()->user();

            // Validate required fields
            $email = $googleUser->getEmail();
            if (!$email) {
                throw new \Exception('Email not provided by Google');
            }

            $name       = $googleUser->getName() ?: $googleUser->getNickname() ?: Str::before($email, '@');
            $avatar     = $googleUser->getAvatar();
            $providerId = $googleUser->getId();

            // Create or update user in transaction
            $user = DB::transaction(function () use ($email, $name, $avatar, $providerId) {
                $existing = User::where('email', $email)->first();

                if ($existing) {
                    // Update provider info if not set, and refresh avatar
                    $updates = [];

                    if (!$existing->provider || !$existing->provider_id) {
                        $updates['provider'] = 'google';
                        $updates['provider_id'] = $providerId;
                    }

                    // Always update avatar if provided by Google
                    if ($avatar) {
                        $updates['avatar'] = $avatar;
                    }

                    // Mark email as verified if not already
                    if (!$existing->email_verified_at) {
                        $updates['email_verified_at'] = now();
                    }

                    if (!empty($updates)) {
                        $existing->update($updates);
                    }

                    return $existing;
                }

                // Create new user
                return User::create([
                    'name'              => $name,
                    'email'             => $email,
                    'password'          => bcrypt(Str::random(40)), // Random password for OAuth users
                    'provider'          => 'google',
                    'provider_id'       => $providerId,
                    'avatar'            => $avatar,
                    'email_verified_at' => now(),
                ]);
            });

            // Generate one-time code (valid for 60 seconds)
            $code = Str::random(64); // Use 64 chars for better security
            Cache::put("oauth_code:{$code}", $user->id, now()->addSeconds(60));

            // Log successful OAuth for debugging
            Log::info('Google OAuth successful', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);

            // IMPORTANT: Redirect to /oauth/callback (not /auth/callback)
            // This should match your Vue router path
            return redirect()->away("{$frontend}/oauth/callback#code={$code}");
        } catch (\Laravel\Socialite\Two\InvalidStateException $e) {
            Log::error('OAuth state mismatch', ['error' => $e->getMessage()]);
            return redirect()->away("{$frontend}/oauth/callback#error=invalid_state");
        } catch (\Exception $e) {
            Log::error('Google OAuth failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return redirect()->away("{$frontend}/oauth/callback#error=oauth_failed");
        }
    }
}
