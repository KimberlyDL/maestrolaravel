<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Requests\UploadAvatarRequest;
use App\Http\Requests\ChangePasswordRequest;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    public function update(UpdateProfileRequest $request)
    {
        $user = $request->user();

        // Email is immutable — do not update it here.
        // $user->first_name = $request->input('first_name', $user->first_name);
        // $user->last_name  = $request->input('last_name',  $user->last_name);
        $user->name  = $request->input('name',  $user->name);
        $user->phone      = $request->input('phone',      $user->phone);
        $user->address    = $request->input('address',    $user->address);
        $user->city       = $request->input('city',       $user->city);
        $user->country    = $request->input('country',    $user->country);
        $user->bio        = $request->input('bio',        $user->bio);
        $user->save();

        return response()->json([
            'message' => 'Profile updated.',
            'user'    => $user,
        ]);
    }

    public function uploadAvatar(UploadAvatarRequest $request)
    {
        $user = $request->user();
        $file = $request->file('avatar');

        // Optional: delete old avatar if stored locally
        if ($user->avatar_path && Storage::disk('public')->exists($user->avatar_path)) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $path = $file->store('avatars', 'public');
        $user->avatar_path = $path;
        $user->avatar_url  = Storage::disk('public')->url($path); // expose full URL for frontend
        $user->save();

        return response()->json([
            'message' => 'Avatar uploaded.',
            'avatar_url' => $user->avatar_url,
        ]);
    }

    public function changePassword(ChangePasswordRequest $request)
    {
        $user = $request->user();

        if (! Hash::check($request->input('current_password'), $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $user->password = Hash::make($request->input('password'));
        $user->save();

        return response()->json(['message' => 'Password changed successfully.']);
    }
}
