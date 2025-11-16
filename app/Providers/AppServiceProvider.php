<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
        $this->app->singleton(\App\Services\UploadService::class, fn() => new \App\Services\UploadService());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
        ResetPassword::createUrlUsing(function ($user, string $token) {
            $frontend = config('app.frontend_url'); // set in config/app.php or .env
            $email = urlencode($user->email);
            return "{$frontend}/reset-password?token={$token}&email={$email}";
        });
    }
}
