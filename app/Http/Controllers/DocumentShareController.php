<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentShare;
use App\Models\DocumentShareAccessLog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use App\Services\ActivityLogger;

class DocumentShareController extends Controller
{
    private const SHARE_TOKEN_LENGTH = 40;
    private const MAX_FAILED_ATTEMPTS = 5;
    private const FAILED_ATTEMPT_WINDOW_MINUTES = 15;

    /**
     * Get or create share configuration for a document
     */
    public function getShare(Document $document)
    {
        $this->authorize('share', $document);

        $share = $document->share()->with(['creator:id,name,email'])->first();

        if (!$share) {
            $share = DocumentShare::create([
                'document_id' => $document->id,
                'access_level' => 'org_only',
                'created_by' => auth()->id(),
                'share_token' => $this->generateSecureToken(),
            ]);
            $share->load('creator:id,name,email');
        }

        return response()->json([
            'id' => $share->id,
            'document_id' => $document->id,
            'access_level' => $share->access_level,
            'share_token' => $share->share_token,
            'share_url' => url("/share/{$share->share_token}"),
            'is_valid' => $share->isValid(),
            'expires_at' => $share->expires_at,
            'max_downloads' => $share->max_downloads,
            'download_count' => $share->download_count,
            'is_password_protected' => $share->is_password_protected,
            'has_download_limit' => $share->has_download_limit,
            'created_by' => $share->creator ? [
                'id' => $share->creator->id,
                'name' => $share->creator->name,
                'email' => $share->creator->email,
            ] : null,
            'created_at' => $share->created_at,
            'updated_at' => $share->updated_at,
        ]);
    }

    /**
     * Update share access level with comprehensive validation
     */
    public function updateShare(Request $request, Document $document)
    {
        $this->authorize('share', $document);

        $data = $request->validate([
            'access_level' => 'required|in:org_only,link,public',
            'expires_at' => 'nullable|date|after:now',
            'max_downloads' => 'nullable|integer|min:1|max:1000',
            'password' => 'nullable|string|min:6|max:255',
            'allowed_ips' => 'nullable|array',
            'allowed_ips.*' => 'ip',
        ]);

        $share = $document->share ?? DocumentShare::create([
            'document_id' => $document->id,
            'created_by' => auth()->id(),
            'share_token' => $this->generateSecureToken(),
        ]);

        // Hash password if provided and not empty
        $password = !empty($data['password']) ? bcrypt($data['password']) : null;

        // If password is being cleared, set to null
        if (array_key_exists('password', $data) && empty($data['password'])) {
            $password = null;
        } else if (!array_key_exists('password', $data)) {
            // Keep existing password if not in request
            $password = $share->password;
        }

        $oldAccessLevel = $share->access_level;

        $share->update([
            'access_level' => $data['access_level'],
            'expires_at' => $data['expires_at'] ?? null,
            'max_downloads' => $data['max_downloads'] ?? null,
            'password' => $password,
            'allowed_ips' => !empty($data['allowed_ips']) ? json_encode($data['allowed_ips']) : null,
            'updated_by' => auth()->id(),
            'revoked_at' => null, // Clear revoked status when updating
        ]);

        // Update document visibility and publish date
        if ($data['access_level'] === 'public') {
            $document->update([
                'visibility' => 'public',
                'published_at' => $document->published_at ?? now(),
            ]);
        } else if ($data['access_level'] === 'org_only') {
            $document->update([
                'visibility' => 'org',
                'published_at' => null,
            ]);
        }

        // Log the change
        ActivityLogger::log(
            $document->organization_id,
            'share_updated',
            Document::class,
            $document->id,
            [
                'old_level' => $oldAccessLevel,
                'new_level' => $data['access_level'],
                'has_password' => !empty($password),
                'has_expiry' => !empty($data['expires_at']),
                'has_download_limit' => !empty($data['max_downloads']),
            ],
            auth()->user()->name . " updated share settings for: {$document->title}"
        );

        return response()->json([
            'message' => 'Share settings updated successfully',
            'share' => [
                'id' => $share->id,
                'access_level' => $share->access_level,
                'share_url' => url("/share/{$share->share_token}"),
                'share_token' => $share->share_token,
                'expires_at' => $share->expires_at,
                'max_downloads' => $share->max_downloads,
                'download_count' => $share->download_count,
                'is_password_protected' => !empty($password),
                'has_download_limit' => !empty($share->max_downloads),
            ],
        ]);
    }

    /**
     * Revoke share link
     */
    public function revokeShare(Document $document)
    {
        $this->authorize('share', $document);

        $share = $document->share;

        if (!$share) {
            return response()->json(['message' => 'No share found for this document'], 404);
        }

        $oldLevel = $share->access_level;

        $share->update([
            'access_level' => 'org_only',
            'password' => null,
            'expires_at' => null,
            'max_downloads' => null,
            'revoked_at' => now(),
            'revoked_by' => auth()->id(),
        ]);

        // Update document visibility
        $document->update([
            'visibility' => 'org',
            'published_at' => null,
        ]);

        ActivityLogger::log(
            $document->organization_id,
            'share_revoked',
            Document::class,
            $document->id,
            ['previous_level' => $oldLevel],
            auth()->user()->name . " revoked share link for: {$document->title}"
        );

        return response()->json(['message' => 'Share link revoked successfully']);
    }

    /**
     * Get share statistics for owner
     */
    public function getShareStats(Document $document)
    {
        $this->authorize('viewShareStats', $document);

        $share = $document->share;

        if (!$share) {
            return response()->json([
                'message' => 'Document has not been shared yet'
            ], 404);
        }

        $totalViews = DocumentShareAccessLog::where('share_id', $share->id)
            ->where('type', 'document_accessed')
            ->where('success', true)
            ->count();

        $uniqueViewers = DocumentShareAccessLog::where('share_id', $share->id)
            ->where('type', 'document_accessed')
            ->where('success', true)
            ->distinct('ip_address')
            ->count();

        $failedAttempts = DocumentShareAccessLog::where('share_id', $share->id)
            ->where('success', false)
            ->count();

        $lastAccessed = DocumentShareAccessLog::where('share_id', $share->id)
            ->where('success', true)
            ->latest()
            ->first();

        // Get recent activity (last 20 accesses)
        $recentActivity = DocumentShareAccessLog::where('share_id', $share->id)
            ->latest()
            ->limit(20)
            ->get()
            ->map(function ($log) {
                return [
                    'type' => $log->type,
                    'success' => $log->success,
                    'ip_address' => $this->maskIp($log->ip_address),
                    'created_at' => $log->created_at,
                ];
            });

        $stats = [
            'share_token' => $share->share_token,
            'access_level' => $share->access_level,
            'total_views' => $totalViews,
            'total_downloads' => $share->download_count,
            'unique_viewers' => $uniqueViewers,
            'failed_attempts' => $failedAttempts,
            'last_accessed_at' => $lastAccessed?->created_at,
            'created_at' => $share->created_at,
            'expires_at' => $share->expires_at,
            'revoked_at' => $share->revoked_at,
            'max_downloads' => $share->max_downloads,
            'remaining_downloads' => $share->getRemainingDownloads(),
            'is_valid' => $share->isValid(),
            'recent_activity' => $recentActivity,
        ];

        return response()->json($stats);
    }

    /**
     * Get access logs for document share
     */
    public function getAccessLogs(Document $document, Request $request)
    {
        $this->authorize('viewShareLogs', $document);

        $share = $document->share;

        if (!$share) {
            return response()->json(['message' => 'No share found for this document'], 404);
        }

        $limit = $request->query('limit', 50);
        $type = $request->query('type');

        $query = DocumentShareAccessLog::where('share_id', $share->id)
            ->orderByDesc('created_at');

        if ($type) {
            $query->where('type', $type);
        }

        $logs = $query->paginate($limit);

        // Mask IP addresses for privacy
        $logs->getCollection()->transform(function ($log) {
            $log->ip_address = $this->maskIp($log->ip_address);
            return $log;
        });

        return response()->json($logs);
    }

    /**
     * Public access: get document by share token with security checks
     * NO AUTHENTICATION REQUIRED
     */
    public function getPublicDocument($token, Request $request)
    {
        // Rate limiting check
        $this->checkRateLimit($token, $request);

        $share = DocumentShare::with([
            'document.organization:id,name,logo',
            'document.latestVersion:id,document_id,version_number,created_at'
        ])
            ->where('share_token', $token)
            ->first();

        if (!$share) {
            $this->logAccessAttempt($token, false, 'invalid_token', $request);
            return response()->json(['message' => 'Invalid share link'], 404);
        }

        // Validate share is still valid
        if (!$share->isValid()) {
            $this->logAccessAttempt($token, false, 'expired_or_revoked', $request);
            return response()->json([
                'message' => 'This share link has expired or been revoked',
                'expired' => true
            ], 403);
        }

        // Check IP restrictions
        if (!$this->isIpAllowed($share, $request)) {
            $this->logAccessAttempt($token, false, 'ip_not_allowed', $request);
            return response()->json(['message' => 'Access denied from your IP address'], 403);
        }

        // Check if password required (don't verify yet, just inform)
        if ($share->password) {
            $password = $request->query('password');
            if (!$password || !password_verify($password, $share->password)) {
                $this->logAccessAttempt($token, false, 'password_required', $request);
                return response()->json([
                    'message' => 'Password required',
                    'password_required' => true
                ], 403);
            }
        }

        // Check download limit
        if ($share->isDownloadLimitReached()) {
            $this->logAccessAttempt($token, false, 'download_limit_exceeded', $request);
            return response()->json(['message' => 'Download limit reached for this link'], 403);
        }

        // Validate access level
        if ($share->access_level === 'org_only') {
            $this->logAccessAttempt($token, false, 'org_only_access', $request);
            return response()->json(['message' => 'This document is not publicly shared'], 403);
        }

        $document = $share->document;

        // Final security check
        if (!$document) {
            $this->logAccessAttempt($token, false, 'document_not_found', $request);
            return response()->json(['message' => 'Document not found'], 404);
        }

        $this->logAccessAttempt($token, true, 'document_accessed', $request, $document->id);

        return response()->json([
            'id' => $document->id,
            'title' => $document->title,
            'description' => $document->description,
            'organization' => [
                'id' => $document->organization->id,
                'name' => $document->organization->name,
                'logo_url' => $document->organization->logo_url,
            ],
            'created_at' => $document->created_at,
            'file_size' => $document->file_size,
            'file_size_formatted' => $this->formatBytes($document->file_size),
            'mime_type' => $document->mime_type,
            'latest_version' => $document->latestVersion ? [
                'id' => $document->latestVersion->id,
                'version_number' => $document->latestVersion->version_number,
                'created_at' => $document->latestVersion->created_at,
            ] : null,
            'can_download' => true,
            'share_info' => [
                'expires_at' => $share->expires_at,
                'remaining_downloads' => $share->getRemainingDownloads(),
                'time_until_expiry' => $share->getTimeUntilExpiry(),
            ],
        ]);
    }

    /**
     * Get temporary download URL for public access
     * GET /api/share/{token}/download-url
     */
    public function getPublicDownloadUrl($token, Request $request)
    {
        // Rate limiting
        $this->checkRateLimit($token, $request);

        $share = DocumentShare::with('document.latestVersion')
            ->where('share_token', $token)
            ->first();

        if (!$share || !$share->isValid()) {
            $this->logAccessAttempt($token, false, 'download_invalid_token', $request);
            return response()->json(['message' => 'Invalid or expired share link'], 404);
        }

        // Security checks
        if (!$this->isIpAllowed($share, $request)) {
            $this->logAccessAttempt($token, false, 'download_ip_denied', $request);
            return response()->json(['message' => 'Access denied'], 403);
        }

        if ($share->password) {
            $password = $request->query('password');
            if (!$password || !password_verify($password, $share->password)) {
                $this->logAccessAttempt($token, false, 'download_invalid_password', $request);
                return response()->json([
                    'message' => 'Invalid password',
                    'password_required' => true
                ], 403);
            }
        }

        if ($share->isDownloadLimitReached()) {
            $this->logAccessAttempt($token, false, 'download_limit_exceeded', $request);
            return response()->json(['message' => 'Download limit reached'], 403);
        }

        $document = $share->document;
        $version = $document->latestVersion;

        if (!$version) {
            return response()->json(['message' => 'No file version found'], 404);
        }

        $diskName = config('filesystems.default', 'local');
        $disk = Storage::disk($diskName);
        $filePath = $version->file_path;

        if (!$disk->exists($filePath)) {
            $this->logAccessAttempt($token, false, 'file_not_found', $request);
            return response()->json(['message' => 'File not found'], 404);
        }

        // Build filename
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        $safeTitle = preg_replace('/[^A-Za-z0-9_\-]/', '_', $document->title);
        $downloadName = "{$safeTitle}_v{$version->version_number}.{$extension}";

        // Increment download count
        $share->increment('download_count');

        // Generate temporary URL
        try {
            $url = $disk->temporaryUrl(
                $filePath,
                now()->addMinutes(10),
                [
                    'ResponseContentDisposition' => 'attachment; filename="' . $downloadName . '"',
                    'ResponseContentType' => $document->mime_type ?: $this->getMimeTypeFromExtension($extension),
                ]
            );

            $this->logAccessAttempt($token, true, 'download_url_generated', $request, $document->id);

            return response()->json([
                'url' => $url,
                'filename' => $downloadName,
                'expires_in' => 600,
                'file_size' => $document->file_size,
                'mime_type' => $document->mime_type,
            ]);
        } catch (\Exception $e) {
            // Fallback for local storage
            if ($diskName === 'local') {
                $publicToken = $this->generatePublicDownloadToken($token, $document->id);

                return response()->json([
                    'url' => route('documents.public-secure-download', [
                        'token' => $token,
                        'downloadToken' => $publicToken,
                    ]),
                    'filename' => $downloadName,
                    'expires_in' => 600,
                    'file_size' => $document->file_size,
                    'mime_type' => $document->mime_type,
                ]);
            }

            return response()->json([
                'message' => 'Could not generate download URL',
            ], 500);
        }
    }

    /**
     * Secure public download endpoint (fallback for local storage)
     */
    public function securePublicDownload($token, $downloadToken, Request $request)
    {
        // Verify download token
        if (!$this->verifyPublicDownloadToken($downloadToken, $token)) {
            return response()->json(['message' => 'Invalid or expired download token'], 403);
        }

        // Rate limiting
        $this->checkRateLimit($token, $request);

        $share = DocumentShare::with('document.latestVersion')
            ->where('share_token', $token)
            ->first();

        if (!$share || !$share->isValid()) {
            return response()->json(['message' => 'Invalid share link'], 404);
        }

        if (!$this->isIpAllowed($share, $request)) {
            return response()->json(['message' => 'Access denied'], 403);
        }

        $document = $share->document;
        $version = $document->latestVersion;

        if (!$version) {
            return response()->json(['message' => 'No file version found'], 404);
        }

        $disk = Storage::disk('local');
        $filePath = $version->file_path;

        if (!$disk->exists($filePath)) {
            return response()->json(['message' => 'File not found'], 404);
        }

        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        $safeTitle = preg_replace('/[^A-Za-z0-9_\-]/', '_', $document->title);
        $downloadName = "{$safeTitle}_v{$version->version_number}.{$extension}";

        $share->increment('download_count');
        $this->logAccessAttempt($token, true, 'document_downloaded', $request, $document->id);

        return $disk->download($filePath, $downloadName, [
            'Content-Type' => $document->mime_type ?: $this->getMimeTypeFromExtension($extension),
            'Content-Disposition' => 'attachment; filename="' . $downloadName . '"',
        ]);
    }

    /* ==================== HELPER METHODS ==================== */

    private function generateSecureToken(): string
    {
        return Str::random(self::SHARE_TOKEN_LENGTH);
    }

    private function generatePublicDownloadToken(string $shareToken, int $docId): string
    {
        $data = [
            'share' => $shareToken,
            'doc' => $docId,
            'exp' => now()->addMinutes(10)->timestamp,
        ];

        $payload = base64_encode(json_encode($data));
        $signature = hash_hmac('sha256', $payload, config('app.key'));

        return $payload . '.' . $signature;
    }

    private function verifyPublicDownloadToken(string $token, string $shareToken): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return false;
        }

        [$payload, $signature] = $parts;

        $expectedSignature = hash_hmac('sha256', $payload, config('app.key'));
        if (!hash_equals($expectedSignature, $signature)) {
            return false;
        }

        $data = json_decode(base64_decode($payload), true);
        if (!$data) {
            return false;
        }

        if ($data['exp'] < now()->timestamp) {
            return false;
        }

        if ($data['share'] !== $shareToken) {
            return false;
        }

        return true;
    }

    private function isIpAllowed(DocumentShare $share, Request $request): bool
    {
        if (!$share->allowed_ips) {
            return true;
        }

        $allowedIps = json_decode($share->allowed_ips, true);
        $clientIp = $request->ip();

        return in_array($clientIp, $allowedIps);
    }

    private function checkRateLimit($token, Request $request): void
    {
        $key = "share_rate_limit:{$token}:" . $request->ip();
        $attempts = Cache::get($key, 0);

        if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
            abort(429, 'Too many attempts. Please try again later.');
        }
    }

    private function logAccessAttempt(
        $token,
        bool $success,
        string $type,
        Request $request,
        ?int $documentId = null
    ): void {
        $share = DocumentShare::where('share_token', $token)->first();

        if (!$share) {
            return;
        }

        if (!$success) {
            $key = "share_rate_limit:{$token}:" . $request->ip();
            Cache::put($key, Cache::get($key, 0) + 1, now()->addMinutes(self::FAILED_ATTEMPT_WINDOW_MINUTES));
        }

        DocumentShareAccessLog::create([
            'share_id' => $share->id,
            'document_id' => $documentId,
            'type' => $type,
            'success' => $success,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'referrer' => $request->header('referer'),
        ]);
    }

    private function getMimeTypeFromExtension(string $extension): string
    {
        $mimeTypes = [
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'zip' => 'application/zip',
            'rar' => 'application/x-rar-compressed',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'mp3' => 'audio/mpeg',
            'mp4' => 'video/mp4',
        ];

        return $mimeTypes[strtolower($extension)] ?? 'application/octet-stream';
    }

    private function formatBytes($bytes): string
    {
        if (!$bytes) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes > 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    private function maskIp(string $ip): string
    {
        // Mask last octet for IPv4 or last segment for IPv6
        if (strpos($ip, '.') !== false) {
            // IPv4
            $parts = explode('.', $ip);
            $parts[3] = 'xxx';
            return implode('.', $parts);
        } else {
            // IPv6
            $parts = explode(':', $ip);
            $parts[count($parts) - 1] = 'xxxx';
            return implode(':', $parts);
        }
    }
}
