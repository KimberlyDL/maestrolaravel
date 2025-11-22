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

class DocumentShareController extends Controller
{
    private const SHARE_TOKEN_LENGTH = 40;
    private const MAX_FAILED_ATTEMPTS = 5;
    private const FAILED_ATTEMPT_WINDOW_MINUTES = 15;



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
                return response()->json(['message' => 'Invalid password'], 403);
            }
        }

        if ($share->max_downloads && $share->download_count >= $share->max_downloads) {
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
     * GET /api/share/{token}/secure/{downloadToken}
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

        // Security checks (simplified since token was already verified)
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

        // Build filename
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        $safeTitle = preg_replace('/[^A-Za-z0-9_\-]/', '_', $document->title);
        $downloadName = "{$safeTitle}_v{$version->version_number}.{$extension}";

        // Increment download count
        $share->increment('download_count');

        $this->logAccessAttempt($token, true, 'document_downloaded', $request, $document->id);

        return $disk->download($filePath, $downloadName, [
            'Content-Type' => $document->mime_type ?: $this->getMimeTypeFromExtension($extension),
            'Content-Disposition' => 'attachment; filename="' . $downloadName . '"',
        ]);
    }

    /**
     * Generate secure token for public downloads
     */
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

    /**
     * Verify public download token
     */
    private function verifyPublicDownloadToken(string $token, string $shareToken): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return false;
        }

        [$payload, $signature] = $parts;

        // Verify signature
        $expectedSignature = hash_hmac('sha256', $payload, config('app.key'));
        if (!hash_equals($expectedSignature, $signature)) {
            return false;
        }

        // Decode payload
        $data = json_decode(base64_decode($payload), true);
        if (!$data) {
            return false;
        }

        // Check expiry
        if ($data['exp'] < now()->timestamp) {
            return false;
        }

        // Check share token matches
        if ($data['share'] !== $shareToken) {
            return false;
        }

        return true;
    }

    /**
     * Get or create share configuration for a document
     */
    public function getShare(Document $document)
    {
        $this->authorize('view', $document);

        $share = $document->share;

        if (!$share) {
            $share = DocumentShare::create([
                'document_id' => $document->id,
                'access_level' => 'org_only',
                'created_by' => auth()->id(),
                'share_token' => $this->generateSecureToken(),
            ]);
        }

        return response()->json([
            'id' => $share->id,
            'access_level' => $share->access_level,
            'share_token' => $share->share_token,
            'share_url' => route('documents.public-access', ['token' => $share->share_token]),
            'is_valid' => $share->isValid(),
            'expires_at' => $share->expires_at,
            'document_name' => $document->title,
            'created_by' => $share->creator?->name,
            'created_at' => $share->created_at,
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
            'expires_at' => 'nullable|date_format:Y-m-d H:i:s|after:now',
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
        $password = !empty($data['password']) ? bcrypt($data['password']) : $share->password;

        $oldAccessLevel = $share->access_level;

        $share->update([
            'access_level' => $data['access_level'],
            'expires_at' => $data['expires_at'] ?? null,
            'max_downloads' => $data['max_downloads'] ?? null,
            'password' => $password,
            'allowed_ips' => !empty($data['allowed_ips']) ? json_encode($data['allowed_ips']) : null,
            'updated_by' => auth()->id(),
        ]);

        // Log the change
        $this->logShareActivity(
            $document,
            'share_updated',
            [
                'old_level' => $oldAccessLevel,
                'new_level' => $data['access_level'],
                'has_password' => !empty($password),
                'has_expiry' => !empty($data['expires_at']),
            ]
        );

        return response()->json([
            'message' => 'Share settings updated successfully',
            'share' => [
                'access_level' => $share->access_level,
                'share_url' => route('documents.public-access', ['token' => $share->share_token]),
                'expires_at' => $share->expires_at,
                'download_count' => $share->download_count,
            ],
        ]);
    }

    /**
     * Revoke share link
     */
    public function revokeShare(Document $document)
    {
        $this->authorize('share', $document);

        if ($document->share) {
            $oldLevel = $document->share->access_level;
            $document->share->update([
                'access_level' => 'org_only',
                'password' => null,
                'revoked_at' => now(),
                'revoked_by' => auth()->id(),
            ]);

            // Log the revoke
            $this->logShareActivity(
                $document,
                'share_revoked',
                ['previous_level' => $oldLevel]
            );
        }

        return response()->json(['message' => 'Share link revoked successfully']);
    }

    /**
     * Public access: get document by share token with security checks
     * NO AUTHENTICATION REQUIRED
     */
    public function getPublicDocument($token, Request $request)
    {
        // Rate limiting check
        $this->checkRateLimit($token, $request);

        $share = DocumentShare::with('document.organization', 'document.latestVersion')
            ->where('share_token', $token)
            ->first();

        if (!$share) {
            $this->logAccessAttempt($token, false, 'invalid_token', $request);
            return response()->json(['message' => 'Invalid share link'], 404);
        }

        // Validate share is still valid
        if (!$share->isValid()) {
            $this->logAccessAttempt($token, false, 'expired_or_revoked', $request);
            return response()->json(['message' => 'This share link has expired or been revoked'], 403);
        }

        // Check IP restrictions
        if (!$this->isIpAllowed($share, $request)) {
            $this->logAccessAttempt($token, false, 'ip_not_allowed', $request);
            return response()->json(['message' => 'Access denied from your IP address'], 403);
        }

        // Check password if required
        if ($share->password) {
            $password = $request->query('password');
            if (!$password || !password_verify($password, $share->password)) {
                $this->logAccessAttempt($token, false, 'invalid_password', $request);
                return response()->json(['message' => 'Password required or invalid'], 403);
            }
        }

        // Check download limit
        if ($share->max_downloads && $share->download_count >= $share->max_downloads) {
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
        if (!$document || ($share->access_level === 'public' && !$document->isPublic())) {
            $this->logAccessAttempt($token, false, 'document_not_public', $request);
            return response()->json(['message' => 'Document is not publicly available'], 403);
        }

        $this->logAccessAttempt($token, true, 'document_accessed', $request, $document->id);

        return response()->json([
            'id' => $document->id,
            'title' => $document->title,
            'type' => $document->type,
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
        ]);
    }

    /**
     * Download public document with proper MIME type handling
     */
    public function downloadPublicDocument($token, Request $request)
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
                return response()->json(['message' => 'Invalid password'], 403);
            }
        }

        if ($share->max_downloads && $share->download_count >= $share->max_downloads) {
            $this->logAccessAttempt($token, false, 'download_limit_exceeded', $request);
            return response()->json(['message' => 'Download limit reached'], 403);
        }

        $document = $share->document;
        $version = $document->latestVersion;

        if (!$version) {
            return response()->json(['message' => 'No file version found'], 404);
        }

        $disk = Storage::disk(config('filesystems.default'));
        $filePath = $version->file_path;

        if (!$disk->exists($filePath)) {
            $this->logAccessAttempt($token, false, 'file_not_found', $request);
            return response()->json(['message' => 'File not found'], 404);
        }

        // Increment download count
        $share->increment('download_count');

        // Build download response with correct MIME type
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        $safeTitle = preg_replace('/[^A-Za-z0-9_\-]/', '_', $document->title);
        $downloadName = "{$safeTitle}_v{$version->version_number}.{$extension}";

        // Get MIME type from document or derive from extension
        $mimeType = $document->mime_type ?: $this->getMimeTypeFromExtension($extension);

        $this->logAccessAttempt($token, true, 'document_downloaded', $request, $document->id);

        return $disk->download($filePath, $downloadName, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'attachment; filename="' . $downloadName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName),
            'X-Download-From' => 'Maestro',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * Get share statistics for owner
     */
    public function getShareStats(Document $document)
    {
        $this->authorize('view', $document);

        $share = $document->share;

        if (!$share) {
            return response()->json([
                'message' => 'Document has not been shared yet'
            ], 404);
        }

        $stats = [
            'share_token' => $share->share_token,
            'access_level' => $share->access_level,
            'total_views' => DocumentShareAccessLog::where('share_id', $share->id)
                ->where('type', 'document_accessed')
                ->count(),
            'total_downloads' => $share->download_count,
            'unique_viewers' => DocumentShareAccessLog::where('share_id', $share->id)
                ->where('type', 'document_accessed')
                ->distinct('ip_address')
                ->count(),
            'failed_attempts' => DocumentShareAccessLog::where('share_id', $share->id)
                ->where('success', false)
                ->count(),
            'last_accessed' => DocumentShareAccessLog::where('share_id', $share->id)
                ->where('success', true)
                ->latest()
                ->value('created_at'),
            'created_at' => $share->created_at,
            'expires_at' => $share->expires_at,
            'revoked_at' => $share->revoked_at,
        ];

        return response()->json($stats);
    }

    /**
     * Get access logs for document share
     */
    public function getAccessLogs(Document $document, Request $request)
    {
        $this->authorize('view', $document);

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

        return response()->json($logs);
    }

    /* ==================== HELPER METHODS ==================== */

    /**
     * Generate cryptographically secure token
     */
    private function generateSecureToken(): string
    {
        return bin2hex(random_bytes(self::SHARE_TOKEN_LENGTH / 2));
    }

    /**
     * Check if IP is in allowed list
     */
    private function isIpAllowed(DocumentShare $share, Request $request): bool
    {
        if (!$share->allowed_ips) {
            return true;
        }

        $allowedIps = json_decode($share->allowed_ips, true);
        $clientIp = $request->ip();

        return in_array($clientIp, $allowedIps);
    }

    /**
     * Check rate limiting for share token
     */
    private function checkRateLimit($token, Request $request): void
    {
        $key = "share_rate_limit:{$token}:" . $request->ip();
        $attempts = Cache::get($key, 0);

        if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
            abort(429, 'Too many attempts. Please try again later.');
        }
    }

    /**
     * Log access attempt for security tracking
     */
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

        // Increment rate limit counter for failed attempts
        if (!$success) {
            $key = "share_rate_limit:{$token}:" . $request->ip();
            Cache::increment($key, 1, self::FAILED_ATTEMPT_WINDOW_MINUTES);
        }

        DocumentShareAccessLog::create([
            'share_id' => $share->id,
            'document_id' => $documentId,
            'type' => $type,
            'success' => $success,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'referrer' => $request->referrer(),
        ]);
    }

    /**
     * Log share activity for audit trail
     */
    private function logShareActivity(
        Document $document,
        string $action,
        ?array $metadata = null
    ): void {
        \App\Services\ActivityLogger::log(
            $document->organization_id,
            $action,
            Document::class,
            $document->id,
            array_merge(['document_title' => $document->title], $metadata ?? []),
            auth()->user()->name . " {$action}: {$document->title}"
        );
    }

    /**
     * Get MIME type from file extension
     */
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
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
        ];

        return $mimeTypes[strtolower($extension)] ?? 'application/octet-stream';
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes($bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes > 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
