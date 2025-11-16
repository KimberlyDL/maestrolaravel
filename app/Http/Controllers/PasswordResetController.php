<?php

namespace App\Http\Controllers;


use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Models\User;

class PasswordResetController extends Controller
{
    public function sendResetLink(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email']
        ]);

        // This sends the email using your configured notification
        $status = Password::sendResetLink(
            $request->only('email')
        );

        // Always return 200 with a generic message (don’t leak user existence)
        return response()->json([
            'message' => 'If that email exists, a reset link has been sent.'
        ], 200);
    }

    public function reset(Request $request)
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', 'min:8'],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->save();

                // If you keep "remember_token", you can rotate it here
                // $user->setRememberToken(Str::random(60));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Password reset successful.'], 200);
        }

        // Typical failure: token invalid / expired
        throw ValidationException::withMessages([
            'email' => [__($status)],
        ]);
    }
}
