<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller
{
    public function verify(Request $request, $id, $hash): RedirectResponse
    {
        $user = User::findOrFail($id);

        // Check if email already verified
        if ($user->hasVerifiedEmail()) {
            // return redirect(config('app.frontend_url') . '/account/verify?email=' . $user->email . '&status=already_verified');
            return redirect(config('app.frontend_url') . '/account/login');
        }

        // Verify the signed hash matches
        if (!hash_equals((string)$hash, sha1($user->getEmailForVerification()))) {
            return redirect(config('app.frontend_url') . '/account/verify?email=' . $user->email . '&status=invalid_link');
        }

        // Mark email as verified and fire event
        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        // Redirect to frontend success page
        return redirect(config('app.frontend_url') . '/account/login');
    }
}
