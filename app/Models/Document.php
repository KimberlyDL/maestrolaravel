<?php

namespace App\Models;

use App\Enums\DocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Document extends Model
{
    protected $fillable = [
        'organization_id',
        'parent_id',
        'is_folder',
        'title',
        'description',
        'type',
        'context',
        'visibility',
        'mime_type',
        'file_size',
        'latest_version_id',
        'created_by',
        'uploaded_by',
        'status',
        'published_at',
    ];

    protected $casts = [
        'type' => DocumentType::class,
        'is_folder' => 'boolean',
        'file_size' => 'integer',
        'published_at' => 'datetime',
    ];

    protected $appends = ['can_edit', 'can_delete', 'can_share'];

    /* ==================== Relationships ==================== */

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function versions()
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version_number');
    }

    public function latestVersion()
    {
        return $this->belongsTo(DocumentVersion::class, 'latest_version_id');
    }

    public function share()
    {
        return $this->hasOne(DocumentShare::class);
    }

    public function reviewRequests()
    {
        return $this->hasMany(ReviewRequest::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function parent()
    {
        return $this->belongsTo(Document::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Document::class, 'parent_id');
    }

    /* ==================== Scopes ==================== */

    public function scopeForReview(Builder $query): Builder
    {
        return $query->where('context', 'review');
    }

    public function scopeForStorage(Builder $query): Builder
    {
        return $query->where('context', 'storage');
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('visibility', 'public')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function scopeOrgAccessible(Builder $query, int $orgId): Builder
    {
        return $query->where(function ($q) use ($orgId) {
            $q->where('organization_id', $orgId)
                ->where(function ($q2) {
                    $q2->where('visibility', 'org')
                        ->orWhere('visibility', 'public');
                });
        });
    }

    public function scopeFilesOnly(Builder $query): Builder
    {
        return $query->where('is_folder', false);
    }

    public function scopeFoldersOnly(Builder $query): Builder
    {
        return $query->where('is_folder', true);
    }

    public function scopeRootLevel(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function scopeInFolder(Builder $query, ?int $folderId): Builder
    {
        if ($folderId) {
            return $query->where('parent_id', $folderId);
        }
        return $query->whereNull('parent_id');
    }

    /* ==================== Helper Methods ==================== */

    public function isPublic(): bool
    {
        return $this->visibility === 'public'
            && $this->published_at
            && $this->published_at->isPast();
    }

    // /**
    //  * Check if user can edit this document
    //  * Only uploader or org admin can edit
    //  */
    // public function canEdit(?int $userId = null): bool
    // {
    //     $userId = $userId ?? auth()->id();
    //     if (!$userId) return false;

    //     // Creator can always edit
    //     if ($this->uploaded_by === $userId || $this->created_by === $userId) {
    //         return true;
    //     }

    //     // Org admins can edit
    //     $org = $this->organization;
    //     if (!$org) return false;

    //     $userRole = $org->getUserRole($userId);
    //     return in_array($userRole, ['admin', 'owner']);
    // }

    // /**
    //  * Check if user can delete this document
    //  * Uploader OR admin with delete_documents permission
    //  */
    // public function canDelete(?int $userId = null): bool
    // {
    //     $userId = $userId ?? auth()->id();
    //     if (!$userId) return false;

    //     // Uploader can delete their own uploads
    //     if ($this->uploaded_by === $userId) {
    //         return true;
    //     }

    //     // Check admin permission
    //     $org = $this->organization;
    //     if (!$org) return false;

    //     $userRole = $org->getUserRole($userId);
    //     if (in_array($userRole, ['admin', 'owner'])) {
    //         return true;
    //     }

    //     // Check explicit admin delete permission
    //     $user = \App\Models\User::find($userId);
    //     return $user && $user->hasPermission($this->organization_id, 'admin_delete_documents');
    // }

    // /**
    //  * Check if user can share this document
    //  * Only uploader can share (unless admin)
    //  */
    // public function canShare(?int $userId = null): bool
    // {
    //     $userId = $userId ?? auth()->id();
    //     if (!$userId) return false;

    //     // Uploader can share
    //     if ($this->uploaded_by === $userId) {
    //         return true;
    //     }

    //     // Org admins can share
    //     $org = $this->organization;
    //     if (!$org) return false;

    //     $userRole = $org->getUserRole($userId);
    //     return in_array($userRole, ['admin', 'owner']);
    // }

    /**
     * Check if user can edit this document
     * FIXED: Checks for manage_storage_system OR contribute_to_storage (if owner)
     */
    public function canEdit(?int $userId = null): bool
    {
        $userId = $userId ?? auth()->id();
        if (!$userId) return false;

        $user = User::find($userId);
        if (!$user) return false;

        if (!$this->organization_id) return false;

        // 1. Managers/Admins can edit anything
        if ($user->hasPermission($this->organization_id, 'manage_storage_system')) {
            return true;
        }

        // 2. Creator/Uploader can edit their own files IF they have contribute permission
        if ($this->uploaded_by === $userId || $this->created_by === $userId) {
            return $user->hasPermission($this->organization_id, 'contribute_to_storage');
        }

        return false;
    }

    /**
     * Check if user can delete this document
     * FIXED: Checks for manage_storage_system OR contribute_to_storage (if owner)
     */
    public function canDelete(?int $userId = null): bool
    {
        $userId = $userId ?? auth()->id();
        if (!$userId) return false;

        $user = User::find($userId);
        if (!$user) return false;

        if (!$this->organization_id) return false;

        // 1. Managers/Admins can delete anything
        if ($user->hasPermission($this->organization_id, 'manage_storage_system')) {
            return true;
        }

        // 2. Uploader can delete their own files IF they have contribute permission
        if ($this->uploaded_by === $userId) {
            return $user->hasPermission($this->organization_id, 'contribute_to_storage');
        }

        return false;
    }

    /**
     * Check if user can share this document
     * FIXED: Checks for manage_storage_system OR contribute_to_storage (if owner)
     */
    public function canShare(?int $userId = null): bool
    {
        $userId = $userId ?? auth()->id();
        if (!$userId) return false;

        $user = User::find($userId);
        if (!$user) return false;

        if (!$this->organization_id) return false;

        // 1. Managers/Admins can share anything
        if ($user->hasPermission($this->organization_id, 'manage_storage_system')) {
            return true;
        }

        // 2. Uploader can share their own files IF they have contribute permission
        if ($this->uploaded_by === $userId) {
            return $user->hasPermission($this->organization_id, 'contribute_to_storage');
        }

        return false;
    }

    public function getFormattedSizeAttribute(): string
    {
        if (!$this->file_size) return '—';

        $bytes = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    public function getCanEditAttribute(): bool
    {
        return $this->canEdit();
    }

    public function getCanDeleteAttribute(): bool
    {
        return $this->canDelete();
    }

    public function getCanShareAttribute(): bool
    {
        return $this->canShare();
    }

    public function getBreadcrumbs(): array
    {
        $breadcrumbs = [];
        $current = $this;

        while ($current) {
            array_unshift($breadcrumbs, [
                'id' => $current->id,
                'title' => $current->title,
                'is_folder' => $current->is_folder,
            ]);
            $current = $current->parent;
        }

        return $breadcrumbs;
    }

    /**
     * Get file extension from mime_type or file_path
     */
    public function getFileExtension(): ?string
    {
        if ($this->is_folder) return null;

        // Try from latest version
        if ($this->latestVersion && $this->latestVersion->file_path) {
            return strtoupper(pathinfo($this->latestVersion->file_path, PATHINFO_EXTENSION));
        }

        // Fallback to mime type
        if ($this->mime_type) {
            $mimeMap = [
                'application/pdf' => 'PDF',
                'application/msword' => 'DOC',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'DOCX',
                'application/vnd.ms-excel' => 'XLS',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'XLSX',
                'image/jpeg' => 'JPG',
                'image/png' => 'PNG',
                'text/plain' => 'TXT',
            ];

            return $mimeMap[$this->mime_type] ?? 'FILE';
        }

        return 'FILE';
    }
}
