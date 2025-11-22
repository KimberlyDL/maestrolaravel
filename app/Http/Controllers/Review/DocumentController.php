<?php

namespace App\Http\Controllers\Review;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Services\UploadService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Models\Organization;

class DocumentController extends Controller
{
    public function __construct(private readonly UploadService $uploads) {}


        /**
     * Generate temporary signed URL for download
     * GET /api/documents/{document}/versions/{version}/download-url
     */
    public function getDownloadUrl(?Organization $organization, Document $document, DocumentVersion $version)
    {
        // Verify version belongs to document
        if ($version->document_id !== $document->id) {
            return response()->json([
                'message' => 'Version not found for this document',
            ], 404);
        }

        $this->authorize('view', $document);

        $diskName = config('filesystems.default', 'local');
        $disk = Storage::disk($diskName);
        $filePath = $version->file_path;

        // Check file exists
        if (!$disk->exists($filePath)) {
            return response()->json([
                'message' => 'File not found',
                'code' => 'FILE_NOT_FOUND',
            ], 404);
        }

        // Build safe filename
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        $safeTitle = preg_replace('/[^A-Za-z0-9_\-]/', '_', $document->title);
        $downloadName = "{$safeTitle}_v{$version->version_number}";
        if ($extension) {
            $downloadName .= ".{$extension}";
        }

        // Generate temporary URL (valid for 10 minutes)
        try {
            $url = $disk->temporaryUrl(
                $filePath,
                now()->addMinutes(10),
                [
                    'ResponseContentDisposition' => 'attachment; filename="' . $downloadName . '"',
                    'ResponseContentType' => $document->mime_type ?: $this->getMimeTypeFromExtension($extension),
                ]
            );

            return response()->json([
                'url' => $url,
                'filename' => $downloadName,
                'expires_in' => 600, // seconds
                'file_size' => $document->file_size,
                'mime_type' => $document->mime_type,
            ]);

        } catch (\Exception $e) {
            // Fallback to direct download for local disk
            if ($diskName === 'local') {
                // Generate a one-time token for secure local download
                $token = $this->generateSecureToken($document->id, $version->id);
                
                return response()->json([
                    'url' => route('documents.secure-download', [
                        'document' => $document->id,
                        'version' => $version->id,
                        'token' => $token,
                    ]),
                    'filename' => $downloadName,
                    'expires_in' => 600,
                    'file_size' => $document->file_size,
                    'mime_type' => $document->mime_type,
                ]);
            }

            return response()->json([
                'message' => 'Could not generate download URL',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Secure download endpoint for local storage (fallback)
     * GET /api/documents/{document}/versions/{version}/secure/{token}
     */
    public function secureDownload(Document $document, DocumentVersion $version, string $token)
    {
        // Verify version belongs to document
        if ($version->document_id !== $document->id) {
            return response()->json(['message' => 'Invalid request'], 404);
        }

        // Verify token
        if (!$this->verifySecureToken($token, $document->id, $version->id)) {
            return response()->json(['message' => 'Invalid or expired token'], 403);
        }

        $this->authorize('view', $document);

        $disk = Storage::disk('local');
        $filePath = $version->file_path;

        if (!$disk->exists($filePath)) {
            return response()->json(['message' => 'File not found'], 404);
        }

        // Build filename
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        $safeTitle = preg_replace('/[^A-Za-z0-9_\-]/', '_', $document->title);
        $downloadName = "{$safeTitle}_v{$version->version_number}.{$extension}";

        // Get MIME type
        $mimeType = $document->mime_type ?: $this->getMimeTypeFromExtension($extension);

        return $disk->download($filePath, $downloadName, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'attachment; filename="' . $downloadName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName),
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * Generate secure one-time token for local downloads
     */
    private function generateSecureToken(int $docId, int $versionId): string
    {
        $data = [
            'doc' => $docId,
            'ver' => $versionId,
            'exp' => now()->addMinutes(10)->timestamp,
            'user' => auth()->id(),
        ];

        $payload = base64_encode(json_encode($data));
        $signature = hash_hmac('sha256', $payload, config('app.key'));

        return $payload . '.' . $signature;
    }

    /**
     * Verify secure token
     */
    private function verifySecureToken(string $token, int $docId, int $versionId): bool
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

        // Check document and version
        if ($data['doc'] !== $docId || $data['ver'] !== $versionId) {
            return false;
        }

        return true;
    }

    /**
     * Enhanced MIME type detection
     */
    private function getMimeTypeFromExtension(string $extension): string
    {
        $mimeTypes = [
            // Documents
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            
            // Text
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            'json' => 'application/json',
            'xml' => 'application/xml',
            
            // Archives
            'zip' => 'application/zip',
            'rar' => 'application/x-rar-compressed',
            '7z' => 'application/x-7z-compressed',
            
            // Images
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            
            // Audio
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            
            // Video
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
        ];

        return $mimeTypes[strtolower($extension)] ?? 'application/octet-stream';
    }

    /**
     * Store document for storage context (Google Drive-like)
     * FIXED: Better MIME type detection
     */
    private function storeStorageDocument(Request $req)
    {
        $data = $req->validate([
            'organization_id' => ['required', 'exists:organizations,id'],
            'parent_id' => ['nullable', 'exists:documents,id'],
            'file' => ['required', 'file', 'max:51200'], // 50MB
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'visibility' => ['required', 'in:private,org,public'],
        ]);

        $this->authorize('uploadToStorage', $data['organization_id']);

        $file = $req->file('file');
        $originalName = $file->getClientOriginalName();
        $title = $data['title'] ?? pathinfo($originalName, PATHINFO_FILENAME);

        // Get extension and determine MIME type
        $extension = strtolower($file->getClientOriginalExtension());
        $mimeType = $file->getMimeType() ?: $this->getMimeTypeFromExtension($extension);

        $document = null;
        DB::transaction(function () use ($data, $file, $title, $mimeType, &$document) {
            // Create document with proper MIME type
            $document = Document::create([
                'organization_id' => $data['organization_id'],
                'parent_id' => $data['parent_id'] ?? null,
                'title' => $title,
                'description' => $data['description'] ?? null,
                'context' => 'storage',
                'visibility' => $data['visibility'],
                'mime_type' => $mimeType,
                'file_size' => $file->getSize(),
                'created_by' => auth()->id(),
                'uploaded_by' => auth()->id(),
                'type' => 'other',
                'published_at' => $data['visibility'] === 'public' ? now() : null,
            ]);

            // Store file
            $stored = $this->uploads->storeDocumentVersion($document->id, $file);

            // Create version
            $version = DocumentVersion::create([
                'document_id' => $document->id,
                'version_number' => 1,
                'file_path' => $stored['path'],
                'note' => 'Initial upload',
                'uploaded_by' => auth()->id(),
            ]);

            $document->update(['latest_version_id' => $version->id]);
        });

        return response()->json($document->load(['latestVersion', 'uploader']), 201);
    }

    /**
     * Download a specific version
     * FIXED: Better MIME type and filename handling
     */
    public function downloadVersion(Document $document, DocumentVersion $version)
    {
        // Verify the version belongs to this document
        if ($version->document_id !== $document->id) {
            return response()->json([
                'message' => 'Version not found for this document',
                'code'    => 'VERSION_MISMATCH',
            ], 404);
        }

        $this->authorize('view', $document);

        // Get the configured disk
        $diskName = config('filesystems.default', 'local');
        $disk = Storage::disk($diskName);
        $filePath = $version->file_path;

        // File existence check
        if (!$disk->exists($filePath)) {
            return response()->json([
                'message' => 'File not found',
                'code'    => 'FILE_NOT_FOUND',
                'path'    => $filePath,
            ], 404);
        }

        // Get file extension from stored path
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);

        // Determine MIME type (prefer stored, fallback to extension)
        $mimeType = $document->mime_type;
        if (!$mimeType || $mimeType === 'application/octet-stream') {
            $mimeType = $this->getMimeTypeFromExtension($extension);
        }

        // Build filename - use stored title + version + original extension
        $safeTitle = preg_replace('/[^A-Za-z0-9_\-]/', '_', $document->title);
        $downloadName = $safeTitle . '_v' . (int)$version->version_number;

        if ($extension) {
            $downloadName .= ".{$extension}";
        }

        // RFC 5987 & RFC 2231 compliant headers
        $encodedFilename = rawurlencode($downloadName);

        $headers = [
            'Content-Type' => $mimeType,
            'Content-Disposition' => "attachment; filename=\"{$downloadName}\"; filename*=UTF-8''{$encodedFilename}",
            'X-Download-From' => 'Maestro',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ];

        // Use Storage::download() - works universally across all disks
        return $disk->download($filePath, $downloadName, $headers);
    }

    /**
     * Create a new document with version 1
     * Supports both 'review' and 'storage' contexts
     */
    public function store(Request $req)
    {
        $context = $req->input('context', 'review');

        if ($context === 'storage') {
            return $this->storeStorageDocument($req);
        }

        // Original review document creation
        $data = $req->validate([
            'organization_id' => ['required', 'exists:organizations,id'],
            'title' => ['required', 'string', 'max:255'],
            'type'  => ['required', Rule::in(['finance_report', 'event_proposal', 'other'])],
            'file'  => ['required', 'file', 'max:20480'],
            'note'  => ['nullable', 'string', 'max:2000'],
        ]);

        $this->authorize('createDocument', [Document::class, $data['organization_id']]);

        $doc = null;
        DB::transaction(function () use ($data, &$doc) {
            $doc = Document::create([
                'organization_id' => $data['organization_id'],
                'title'           => $data['title'],
                'type'            => $data['type'],
                'context'         => 'review',
                'created_by'      => auth()->id(),
            ]);

            $stored = $this->uploads->storeDocumentVersion($doc->id, $data['file']);

            $ver = DocumentVersion::create([
                'document_id'    => $doc->id,
                'version_number' => 1,
                'file_path'      => $stored['path'],
                'note'           => $data['note'] ?? null,
                'uploaded_by'    => auth()->id(),
            ]);

            $doc->update(['latest_version_id' => $ver->id]);
        });

        return response()->json($doc->load('latestVersion'), 201);
    }

    /**
     * Add a new version to an EXISTING document
     */
    public function addVersion(Request $req, Document $document)
    {
        $this->authorize('addVersion', $document);

        $data = $req->validate([
            'file' => ['required', 'file', 'max:20480'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $ver = null;
        DB::transaction(function () use ($data, $document, &$ver) {
            $maxVersion = $document->versions()->max('version_number') ?? 0;
            $nextVersion = (int)$maxVersion + 1;

            $stored = $this->uploads->storeDocumentVersion($document->id, $data['file']);

            $ver = DocumentVersion::create([
                'document_id'    => $document->id,
                'version_number' => $nextVersion,
                'file_path'      => $stored['path'],
                'note'           => $data['note'] ?? null,
                'uploaded_by'    => auth()->id(),
            ]);

            // Update file size and MIME type if storage context
            if ($document->context === 'storage') {
                $file = $data['file'];
                $extension = strtolower($file->getClientOriginalExtension());
                $mimeType = $file->getMimeType() ?: $this->getMimeTypeFromExtension($extension);

                $document->update([
                    'latest_version_id' => $ver->id,
                    'file_size' => $file->getSize(),
                    'mime_type' => $mimeType,
                ]);
            } else {
                $document->update(['latest_version_id' => $ver->id]);
            }
        });

        return response()->json($ver, 201);
    }

    /**
     * Show document with all versions
     */
    public function show(Document $document)
    {
        $this->authorize('view', $document);

        return $document->load([
            'organization',
            'latestVersion',
            'uploader',
            'parent',
            'versions' => function ($query) {
                $query->orderByDesc('version_number');
            }
        ]);
    }

    /**
     * List all documents for an organization
     * Supports filtering by context
     */
    public function index(Request $req)
    {
        $orgId = $req->query('organization_id');
        $context = $req->query('context', 'review');

        if (!$orgId) {
            return response()->json(['message' => 'organization_id required'], 400);
        }

        if ($context === 'storage') {
            $this->authorize('viewStorage', [Document::class, $orgId]);
        } else {
            $this->authorize('viewDocuments', [Document::class, $orgId]);
        }

        $query = Document::query()
            ->where('organization_id', $orgId)
            ->where('context', $context)
            ->with(['latestVersion', 'organization', 'uploader:id,name,email,avatar,avatar_url']);

        if ($context === 'storage') {
            $folderId = $req->query('folder_id');
            $search = $req->query('q');

            if ($folderId) {
                $query->where('parent_id', $folderId);
            } else {
                $query->whereNull('parent_id');
            }

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            }

            $query->orderByRaw('is_folder DESC, title ASC');
        } else {
            $query->orderByDesc('created_at');
        }

        $documents = $query->paginate($context === 'storage' ? 50 : 15);

        return response()->json($documents);
    }
}
