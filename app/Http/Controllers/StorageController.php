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

    // /**
    //  * List documents/folders in organization storage
    //  */
    // public function index(Request $request)
    // {
    //     // Handle route param or query param
    //     $orgId = $request->route('organization') ?? $request->input('organization_id');
    //     $folderId = $request->input('folder_id');
    //     $search = $request->input('q');
    //     $type = $request->input('type');

    //     if (!$orgId) {
    //         return response()->json(['message' => 'organization_id required'], 400);
    //     }

    //     $this->authorize('viewStorage', [Document::class, $orgId]);

    //     $query = Document::forStorage()
    //         ->where('organization_id', $orgId)
    //         ->with([
    //             'uploader:id,name,email,avatar,avatar_url',
    //             'latestVersion:id,document_id,version_number,created_at,file_path',
    //             'parent:id,title',
    //             'organization:id,name'
    //         ])
    //         ->withCount('children');

    //     if ($folderId) {
    //         $query->where('parent_id', $folderId);
    //     } else {
    //         $query->rootLevel();
    //     }

    //     if ($type === 'folders') {
    //         $query->foldersOnly();
    //     } elseif ($type === 'files') {
    //         $query->filesOnly();
    //     }

    //     if ($search) {
    //         $query->where(function ($q) use ($search) {
    //             $q->where('title', 'like', "%{$search}%")
    //                 ->orWhere('description', 'like', "%{$search}%");
    //         });
    //     }

    //     $query->orderByRaw('is_folder DESC, title ASC');

    //     $documents = $query->paginate(50);

    //     $documents->getCollection()->transform(function ($doc) {
    //         $doc->file_extension = $doc->getFileExtension();
    //         $doc->is_shared_public = $doc->visibility === 'public';
    //         return $doc;
    //     });

    //     $breadcrumbs = [];
    //     if ($folderId) {
    //         $folder = Document::find($folderId);
    //         if ($folder) {
    //             $breadcrumbs = $folder->getBreadcrumbs();
    //         }
    //     }

    //     return response()->json([
    //         'data' => $documents->items(),
    //         'meta' => [
    //             'current_page' => $documents->currentPage(),
    //             'last_page' => $documents->lastPage(),
    //             'per_page' => $documents->perPage(),
    //             'total' => $documents->total(),
    //         ],
    //         'breadcrumbs' => $breadcrumbs,
    //     ]);
    // }

    /**
     * List documents/folders in organization storage
     */
    public function index(Request $request)
    {
        // Handle route param or query param
        $orgId = $request->route('organization') ?? $request->input('organization_id');
        $folderId = $request->input('folder_id');
        $search = $request->input('q');
        $type = $request->input('type');

        if (!$orgId) {
            return response()->json(['message' => 'organization_id required'], 400);
        }

        $this->authorize('viewStorage', [Document::class, $orgId]);

        $query = Document::forStorage()
            ->where('organization_id', $orgId)
            ->with([
                'uploader:id,name,email,avatar,avatar_url',
                'latestVersion:id,document_id,version_number,created_at,file_path',
                // FIX: Select necessary columns for permission checks on the parent folder
                'parent:id,title,organization_id,uploaded_by,created_by',
                'organization:id,name'
            ])
            ->withCount('children');

        if ($folderId) {
            $query->where('parent_id', $folderId);
        } else {
            $query->rootLevel();
        }

        if ($type === 'folders') {
            $query->foldersOnly();
        } elseif ($type === 'files') {
            $query->filesOnly();
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $query->orderByRaw('is_folder DESC, title ASC');

        $documents = $query->paginate(50);

        $documents->getCollection()->transform(function ($doc) {
            $doc->file_extension = $doc->getFileExtension();
            $doc->is_shared_public = $doc->visibility === 'public';
            return $doc;
        });

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

    public function publicIndex(Request $request)
    {
        $search = $request->input('q');
        $perPage = $request->input('per_page', 50);

        $query = Document::where('visibility', 'public')
            ->where('context', 'storage')
            ->with([
                'uploader:id,name,email',
                'organization:id,name,logo',
                'latestVersion:id,document_id,version_number,created_at,file_path'
            ]);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('organization', function ($subQ) use ($search) {
                        $subQ->where('name', 'like', "%{$search}%");
                    });
            });
        }

        $query->latest();
        $documents = $query->paginate($perPage);

        $documents->getCollection()->transform(function ($doc) {
            $doc->file_extension = $doc->getFileExtension();
            return $doc;
        });

        return response()->json($documents);
    }

    public function createFolder(Request $request)
    {
        $data = $request->validate([
            'organization_id' => 'required|exists:organizations,id',
            'parent_id' => 'nullable|exists:documents,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $this->authorize('uploadToStorage', [Document::class, $data['organization_id']]);

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

    public function upload(Request $request)
    {
        $data = $request->validate([
            'organization_id' => 'required|exists:organizations,id',
            'parent_id' => 'nullable|exists:documents,id',
            'file' => 'required|file|max:51200',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $this->authorize('uploadToStorage', [Document::class, $data['organization_id']]);

        $file = $request->file('file');
        $title = $data['title'] ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        $document = null;
        DB::transaction(function () use ($data, $file, $title, &$document) {
            $document = Document::create([
                'organization_id' => $data['organization_id'],
                'parent_id' => $data['parent_id'] ?? null,
                'title' => $title,
                'description' => $data['description'] ?? null,
                'context' => 'storage',
                'visibility' => 'org',
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'created_by' => auth()->id(),
                'uploaded_by' => auth()->id(),
                'type' => 'other',
            ]);

            $stored = $this->uploads->storeDocumentVersion($document->id, $file);

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
            ['document_name' => $document->title],
            auth()->user()->name . " uploaded: {$document->title}"
        );

        $document->load(['latestVersion', 'uploader', 'organization']);
        $document->file_extension = $document->getFileExtension();
        $document->is_shared_public = false;

        return response()->json($document, 201);
    }

    /**
     * Update document/folder (Rename/Move)
     * FIX: Added $organization param to match route definition
     */
    public function update(Request $request, $organization, Document $document)
    {
        $this->authorize('updateStorage', $document);

        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
            'parent_id' => 'nullable|exists:documents,id',
        ]);

        if (isset($data['parent_id']) && $document->is_folder) {
            if ($data['parent_id'] === $document->id) {
                return response()->json(['message' => 'Cannot move folder into itself'], 400);
            }
            $parent = Document::find($data['parent_id']);
            if ($parent && $this->isDescendant($document->id, $parent)) {
                return response()->json(['message' => 'Cannot move folder into its own subfolder'], 400);
            }
        }

        $oldData = $document->only(['title', 'parent_id']);
        $document->update($data);

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
     * FIX: Accepts $organization string and $id string to bypass implicit model binding errors
     * This allows Admins to delete files they don't own without 404s
     */
    public function destroy($organization, string $id)
    {
        // Manual lookup to handle "Not Found" gracefully and ignore ownership scopes
        $document = Document::where('id', $id)
            ->where('organization_id', $organization)
            ->first();

        if (!$document) {
            return response()->json(['message' => 'Document not found'], 404);
        }

        // Policy check will allow 'manage_storage_system' to pass
        $this->authorize('deleteStorage', $document);

        $title = $document->title;
        $orgId = $document->organization_id;
        $isFolder = $document->is_folder;

        DB::transaction(function () use ($document) {
            if ($document->is_folder) {
                $this->deleteFolder($document);
            } else {
                foreach ($document->versions as $version) {
                    if (Storage::exists($version->file_path)) {
                        Storage::delete($version->file_path);
                    }
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
     * Show details
     * FIX: Added $organization param
     */
    public function show($organization, Document $document)
    {
        $this->authorize('viewStorage', [Document::class, $document->organization_id]);

        if ($document->context !== 'storage') {
            return response()->json(['message' => 'Not a storage document'], 400);
        }

        $document->load(['uploader', 'organization', 'parent', 'versions', 'latestVersion']);
        $document->file_extension = $document->getFileExtension();
        $document->is_shared_public = $document->visibility === 'public';

        return response()->json($document);
    }

    /**
     * Storage Statistics
     * FIX: Added $organization param
     */
    public function statistics(Request $request, $organization)
    {
        // Route param is passed as $organization argument
        $orgId = $organization;

        if (!$orgId) {
            return response()->json(['message' => 'organization_id required'], 400);
        }

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
            'total_size' => (int)($stats->total_size ?? 0),
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
                foreach ($child->versions as $version) {
                    if (Storage::exists($version->file_path)) {
                        Storage::delete($version->file_path);
                    }
                }
            }
            $child->delete();
        }
    }

    private function isDescendant(int $ancestorId, Document $document): bool
    {
        $current = $document;
        while ($current) {
            if ($current->id === $ancestorId) return true;
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
