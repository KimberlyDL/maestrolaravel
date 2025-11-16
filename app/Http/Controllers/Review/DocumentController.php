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

class DocumentController extends Controller
{
    public function __construct(private readonly UploadService $uploads) {}

    /**
     * Create a new document with version 1
     * Supports both 'review' and 'storage' contexts
     */
    public function store(Request $req)
    {
        // Determine context from request or default to 'review' for backward compatibility
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
     * Store document for storage context (Google Drive-like)
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
        $title = $data['title'] ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        $document = null;
        DB::transaction(function () use ($data, $file, $title, &$document) {
            // Create document
            $document = Document::create([
                'organization_id' => $data['organization_id'],
                'parent_id' => $data['parent_id'] ?? null,
                'title' => $title,
                'description' => $data['description'] ?? null,
                'context' => 'storage',
                'visibility' => $data['visibility'],
                'mime_type' => $file->getMimeType(),
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

            // Update file size if storage context
            if ($document->context === 'storage') {
                $document->update([
                    'latest_version_id' => $ver->id,
                    'file_size' => $data['file']->getSize(),
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
     * Download a specific version
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

        // Use the default disk (or switch to a specific disk if you store there)
        $disk = Storage::disk(config('filesystems.default'));
        $filePath = $version->file_path;

        // File existence check
        if (!$disk->exists($filePath)) {
            return response()->json([
                'message' => 'File not found',
                'code'    => 'FILE_NOT_FOUND',
                'path'    => $filePath,
            ], 404);
        }

        // Build a user-friendly filename, keep extension as stored
        $extension  = pathinfo($filePath, PATHINFO_EXTENSION);
        $safeTitle  = preg_replace('/[^A-Za-z0-9_\-]/', '_', $document->title);
        $downloadName = $safeTitle . '_v' . (int) $version->version_number . ($extension ? ".{$extension}" : '');

        // Determine MIME type with sane fallback
        $mimeType = $disk->mimeType($filePath) ?: 'application/octet-stream';

        // Set Content-Disposition (RFC 5987) for broader filename support
        $headers = [
            'Content-Type'              => $mimeType,
            'Content-Disposition'       => 'attachment; filename="' . addslashes($downloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName),
            'X-Download-From'           => 'Maestro',
        ];

        /**
         * Use the Storage::download() helper so it works on ANY disk (local, public, s3, etc.).
         * This avoids Storage::path() which only works on local-like disks.
         */
        return $disk->download($filePath, $downloadName, $headers);
    }

    /**
     * List all documents for an organization
     * Supports filtering by context
     */
    public function index(Request $req)
    {
        $orgId = $req->query('organization_id');
        $context = $req->query('context', 'review'); // Default to 'review' for backward compatibility

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

        // For storage context, include parent and folder info
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
