<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class UploadController extends Controller
{
    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:10240', // 10MB limit
        ]);

        $path = $request->file('file')->store('documents', 's3');
        $url = Storage::disk('s3')->url($path);

        return response()->json([
            'message' => 'File uploaded successfully!',
            'path' => $path,
            'url' => $url,
        ]);
    }
}
