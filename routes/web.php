<?php

use Illuminate\Support\Facades\Route;
// use App\Http\Controllers\OAuth\GoogleController; 
use App\Http\Controllers\VerifyEmailController;

// Route::get('/oauth/google/redirect', [GoogleController::class, 'redirect'])
//     ->name('oauth.google.redirect');

// Route::get('/oauth/google/callback', [GoogleController::class, 'callback'])
//     ->name('oauth.google.callback');
// Email verification link (web, signed)
Route::get('/email/verify/{id}/{hash}', [VerifyEmailController::class, 'verify'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');
