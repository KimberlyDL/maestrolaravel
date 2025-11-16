<?php


namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleController extends Controller
{
    public function redirect()
    {
        return Socialite::driver('google')
            ->scopes(['openid','profile','email'])
            ->redirectUrl(config('services.google.redirect')) 
            ->redirect();
    }

    public function callback()
    {
        $googleUser = Socialite::driver('google')
            ->redirectUrl(config('services.google.redirect'))
            ->user();

        // Find or create local user
$user = User::firstOrCreate(
        ['email' => $googleUser->getEmail()],
        [
            'name'              => $googleUser->getName() ?: Str::before($googleUser->getEmail(), '@'),
            'password'          => bcrypt(Str::random(40)),
            'email_verified_at' => now(),
            'provider'          => 'google',
            'provider_id'       => $googleUser->getId(),
            'avatar'            => $googleUser->getAvatar(),
        ]
    );

        // Create a one-time code (valid for 60s) to exchange for JWT via /api
         $code = Str::random(64);
        Cache::put("oauth_code:{$code}", $user->id, now()->addSeconds(60)); 

        // Redirect back to your SPA with the code
        $frontend = config('app.frontend_url');
        return redirect()->away("{$frontend}/oauth/callback?code={$code}");
    }
}
