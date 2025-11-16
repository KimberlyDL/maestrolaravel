<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Services\UploadService;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StorageController extends Controller
{
    public function __construct(
        private readonly UploadService $uploads
    ) {}

    /**
     * List documents/folders in organization storage
     */
    public function index(Request $request)
    {
        $orgId = $request->input('organization_id');
        $folderId = $request->input('folder_id');
        $search = $request->input('q');
        $type = $request->input('type'); // 'all', 'folders', 'files'

        if (!$orgId) {
            return response()->json(['message' => 'organization_id required'], 400);
        }

        // FIX: Pass as array with Document class and orgId
        $this->authorize('viewStorage', [Document::class, $orgId]);

        $query = Document::forStorage()
            ->where('organization_id', $orgId)
            ->with([
                'uploader:id,name,email,avatar,avatar_url',
                'latestVersion:id,document_id,version_number,created_at',
                'parent:id,title'
            ]);

        // Filter by folder
        if ($folderId) {
            $query->where('parent_id', $folderId);
        } else {
            $query->rootLevel();
        }

        // Filter by type
        if ($type === 'folders') {
            $query->foldersOnly();
        } elseif ($type === 'files') {
            $query->filesOnly();
        }

        // Search
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Sort: folders first, then by name
        $query->orderByRaw('is_folder DESC, title ASC');

        $documents = $query->paginate(50);

        // Get breadcrumbs if in folder
        $breadcrumbs = [];
        if ($folderId) {
            $folder = Document::find($folderId);
            if ($folder) {
                $breadcrumbs = $folder->getBreadcrumbs();
            }
        }

        return response()->json([
            'data' => $documents->items(),
            'meta' => [
                'current_page' => $documents->currentPage(),
                'last_page' => $documents->lastPage(),
                'per_page' => $documents->perPage(),
                'total' => $documents->total(),
            ],
            'breadcrumbs' => $breadcrumbs,
        ]);
    }

    /**
     * Get public documents
     */
    public function publicIndex(Request $request)
    {
        $search = $request->input('q');

        $query = Document::forStorage()
            ->public()
            ->filesOnly() // Only files can be public
            ->with([
                'uploader:id,name',
                'organization:id,name,logo,logo_url',
                'latestVersion:id,document_id,version_number'
            ]);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $documents = $query->latest()->paginate(20);

        return response()->json($documents);
    }

    /**
     * Create a new folder
     */
    public function createFolder(Request $request)
    {
        $data = $request->validate([
            'organization_id' => 'required|exists:organizations,id',
            'parent_id' => 'nullable|exists:documents,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        // FIX: Pass as array
        $this->authorize('uploadToStorage', [Document::class, $data['organization_id']]);

        // Verify parent is a folder if provided
        if (!empty($data['parent_id'])) {
            $parent = Document::find($data['parent_id']);
            if (!$parent || !$parent->is_folder) {
                return response()->json(['message' => 'Parent must be a folder'], 400);
            }
        }

        $folder = Document::create([
            'organization_id' => $data['organization_id'],
            'parent_id' => $data['parent_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'is_folder' => true,
            'context' => 'storage',
            'visibility' => 'org',
            'created_by' => auth()->id(),
            'uploaded_by' => auth()->id(),
        ]);

        ActivityLogger::log(
            $data['organization_id'],
            'folder_created',
            Document::class,
            $folder->id,
            ['folder_name' => $folder->title],
            auth()->user()->name . " created folder: {$folder->title}"
        );

        return response()->json($folder->load('parent'), 201);
    }

    /**
     * Upload document to storage
     */
    public function upload(Request $request)
    {
        $data = $request->validate([
            'organization_id' => 'required|exists:organizations,id',
            'parent_id' => 'nullable|exists:documents,id',
            'file' => 'required|file|max:51200', // 50MB
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'visibility' => 'required|in:private,org,public',
        ]);

        // FIX: Pass as array
        $this->authorize('uploadToStorage', [Document::class, $data['organization_id']]);

        $file = $request->file('file');
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

        ActivityLogger::log(
            $data['organization_id'],
            'document_uploaded',
            Document::class,
            $document->id,
            [
                'document_name' => $document->title,
                'file_size' => $document->file_size,
                'visibility' => $document->visibility,
            ],
            auth()->user()->name . " uploaded: {$document->title}"
        );

        return response()->json($document->load(['latestVersion', 'uploader']), 201);
    }

    /**
     * Update document/folder details
     */
    public function update(Request $request, Document $document)
    {
        $this->authorize('updateStorage', $document);

        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
            'visibility' => 'sometimes|in:private,org,public',
            'parent_id' => 'nullable|exists:documents,id',
        ]);

        // Prevent moving folder into itself or its descendants
        if (isset($data['parent_id']) && $document->is_folder) {
            if ($data['parent_id'] === $document->id) {
                return response()->json(['message' => 'Cannot move folder into itself'], 400);
            }
            // Check if new parent is a descendant
            $parent = Document::find($data['parent_id']);
            if ($parent && $this->isDescendant($document->id, $parent)) {
                return response()->json(['message' => 'Cannot move folder into its own subfolder'], 400);
            }
        }

        $oldData = $document->only(['title', 'visibility', 'parent_id']);

        $document->update($data);

        // Update published_at if visibility changed
        if (isset($data['visibility'])) {
            $document->update([
                'published_at' => $data['visibility'] === 'public' ? now() : null
            ]);
        }

        ActivityLogger::log(
            $document->organization_id,
            'document_updated',
            Document::class,
            $document->id,
            ['old' => $oldData, 'new' => $document->only(array_keys($oldData))],
            auth()->user()->name . " updated: {$document->title}"
        );

        return response()->json($document->load(['parent', 'uploader']));
    }

    /**
     * Delete document/folder
     */
    public function destroy(Document $document)
    {
        $this->authorize('deleteStorage', $document);

        $title = $document->title;
        $orgId = $document->organization_id;
        $isFolder = $document->is_folder;

        DB::transaction(function () use ($document) {
            // If folder, delete all contents recursively
            if ($document->is_folder) {
                $this->deleteFolder($document);
            } else {
                // Delete file versions from storage
                foreach ($document->versions as $version) {
                    Storage::delete($version->file_path);
                }
            }

            $document->delete();
        });

        ActivityLogger::log(
            $orgId,
            $isFolder ? 'folder_deleted' : 'document_deleted',
            Document::class,
            null,
            ['name' => $title],
            auth()->user()->name . " deleted: {$title}"
        );

        return response()->json(['message' => 'Deleted successfully']);
    }

    /**
     * Move document/folder
     */
    public function move(Request $request, Document $document)
    {
        $this->authorize('updateStorage', $document);

        $data = $request->validate([
            'parent_id' => 'nullable|exists:documents,id',
        ]);

        $newParentId = $data['parent_id'] ?? null;

        // Validate target is a folder
        if ($newParentId) {
            $targetParent = Document::find($newParentId);
            if (!$targetParent || !$targetParent->is_folder) {
                return response()->json(['message' => 'Target must be a folder'], 400);
            }

            // Prevent moving folder into itself or descendants
            if ($document->is_folder && $this->isDescendant($document->id, $targetParent)) {
                return response()->json(['message' => 'Cannot move folder into its own subfolder'], 400);
            }
        }

        $oldParent = $document->parent;
        $document->update(['parent_id' => $newParentId]);

        ActivityLogger::log(
            $document->organization_id,
            'document_moved',
            Document::class,
            $document->id,
            [
                'name' => $document->title,
                'from' => $oldParent?->title ?? 'Root',
                'to' => $document->parent?->title ?? 'Root',
            ],
            auth()->user()->name . " moved: {$document->title}"
        );

        return response()->json($document->load('parent'));
    }

    /**
     * Copy document (not folder)
     */
    public function copy(Request $request, Document $document)
    {
        // FIX: Pass as array
        $this->authorize('viewStorage', [Document::class, $document->organization_id]);

        if ($document->is_folder) {
            return response()->json(['message' => 'Cannot copy folders'], 400);
        }

        $data = $request->validate([
            'parent_id' => 'nullable|exists:documents,id',
            'title' => 'nullable|string|max:255',
        ]);

        $copy = null;
        DB::transaction(function () use ($document, $data, &$copy) {
            // Create copy of document
            $copy = $document->replicate();
            $copy->title = $data['title'] ?? ($document->title . ' (Copy)');
            $copy->parent_id = $data['parent_id'] ?? $document->parent_id;
            $copy->created_by = auth()->id();
            $copy->uploaded_by = auth()->id();
            $copy->save();

            // Copy latest version
            $originalVersion = $document->latestVersion;
            if ($originalVersion) {
                // Copy file in storage
                $newPath = str_replace(
                    "documents/{$document->id}",
                    "documents/{$copy->id}",
                    $originalVersion->file_path
                );
                Storage::copy($originalVersion->file_path, $newPath);

                // Create version record
                $newVersion = DocumentVersion::create([
                    'document_id' => $copy->id,
                    'version_number' => 1,
                    'file_path' => $newPath,
                    'note' => 'Copied from original',
                    'uploaded_by' => auth()->id(),
                ]);

                $copy->update(['latest_version_id' => $newVersion->id]);
            }
        });

        ActivityLogger::log(
            $document->organization_id,
            'document_copied',
            Document::class,
            $copy->id,
            ['original' => $document->title, 'copy' => $copy->title],
            auth()->user()->name . " copied: {$document->title}"
        );

        return response()->json($copy->load(['latestVersion', 'uploader']), 201);
    }

    /**
     * Get document/folder details
     */
    public function show(Document $document)
    {
        // FIX: Pass as array
        $this->authorize('viewStorage', [Document::class, $document->organization_id]);

        if ($document->context !== 'storage') {
            return response()->json(['message' => 'Not a storage document'], 400);
        }

        return response()->json($document->load([
            'uploader',
            'organization',
            'parent',
            'versions',
            'latestVersion',
            'share'
        ]));
    }

    /**
     * Get storage statistics
     */
    public function statistics(Request $request)
    {
        $orgId = $request->input('organization_id');

        if (!$orgId) {
            return response()->json(['message' => 'organization_id required'], 400);
        }

        // FIX: Pass as array
        $this->authorize('viewStorage', [Document::class, $orgId]);

        $stats = Document::forStorage()
            ->where('organization_id', $orgId)
            ->selectRaw('
                COUNT(*) as total_items,
                SUM(CASE WHEN is_folder = 1 THEN 1 ELSE 0 END) as total_folders,
                SUM(CASE WHEN is_folder = 0 THEN 1 ELSE 0 END) as total_files,
                SUM(CASE WHEN visibility = "public" THEN 1 ELSE 0 END) as public_files,
                SUM(file_size) as total_size
            ')
            ->first();

        return response()->json([
            'total_items' => $stats->total_items ?? 0,
            'total_folders' => $stats->total_folders ?? 0,
            'total_files' => $stats->total_files ?? 0,
            'public_files' => $stats->public_files ?? 0,
            'total_size' => $stats->total_size ?? 0,
            'total_size_formatted' => $this->formatBytes($stats->total_size ?? 0),
        ]);
    }

    /* ==================== Helper Methods ==================== */

    private function deleteFolder(Document $folder): void
    {
        $children = Document::where('parent_id', $folder->id)->get();

        foreach ($children as $child) {
            if ($child->is_folder) {
                $this->deleteFolder($child);
            } else {
                // Delete file versions
                foreach ($child->versions as $version) {
                    Storage::delete($version->file_path);
                }
            }
            $child->delete();
        }
    }

    private function isDescendant(int $ancestorId, Document $document): bool
    {
        $current = $document;
        while ($current) {
            if ($current->id === $ancestorId) {
                return true;
            }
            $current = $current->parent;
        }
        return false;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
