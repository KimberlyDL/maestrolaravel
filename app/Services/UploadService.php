<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadService
{
    /** Store the primary document version file under documents/{documentId}/versions */
    public function storeDocumentVersion(int $documentId, UploadedFile $file): array
    {
        $name = $this->uniqueName($file);
        $dir  = "documents/{$documentId}/versions";
        $path = Storage::putFileAs($dir, $file, $name);

        return [
            'path' => $path,
            'url'  => $this->publicUrl($path),
        ];
    }

    /** Store a supporting attachment file under reviews/{reviewId}/attachments */
    public function storeReviewAttachment(int $reviewId, UploadedFile $file): array
    {
        $name = $this->uniqueName($file);
        $dir  = "reviews/{$reviewId}/attachments";
        $path = Storage::putFileAs($dir, $file, $name);

        return [
            'path' => $path,
            'url'  => $this->publicUrl($path),
        ];
    }

    /** Temporary signed URL for private buckets (R2 supports this via S3 driver) */
    public function temporaryUrl(string $path, int $minutes = 15): ?string
    {
        try {
            return Storage::temporaryUrl($path, now()->addMinutes($minutes));
        } catch (\Throwable) {
            return null;
        }
    }

    public function delete(string $path): bool
    {
        return Storage::delete($path);
    }

    private function uniqueName(UploadedFile $file): string
    {
        $ext = $file->getClientOriginalExtension() ?: $file->extension();
        return Str::uuid()->toString() . ($ext ? ".{$ext}" : '');
    }

    private function publicUrl(string $path): ?string
    {
        // Prefer configured base URL (CDN); fallback to Storage::url() if available
        $disk = config('filesystems.default', 'r2');
        $base = config("filesystems.disks.{$disk}.url");
        if ($base) {
            return rtrim($base, '/') . '/' . ltrim($path, '/');
        }
        try {
            return Storage::url($path);
        } catch (\Throwable) {
            return null; // private bucket without CDN; use temporaryUrl() when needed
        }
    }
}
