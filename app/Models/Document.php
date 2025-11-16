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

    protected $appends = ['can_edit', 'can_delete'];

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

    /**
     * Scope for review context documents
     */
    public function scopeForReview(Builder $query): Builder
    {
        return $query->where('context', 'review');
    }

    /**
     * Scope for storage context documents
     */
    public function scopeForStorage(Builder $query): Builder
    {
        return $query->where('context', 'storage');
    }

    /**
     * Scope for publicly accessible documents
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('visibility', 'public')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * Scope for organization-accessible documents
     */
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

    /**
     * Scope for files only (not folders)
     */
    public function scopeFilesOnly(Builder $query): Builder
    {
        return $query->where('is_folder', false);
    }

    /**
     * Scope for folders only
     */
    public function scopeFoldersOnly(Builder $query): Builder
    {
        return $query->where('is_folder', true);
    }

    /**
     * Scope for root level items (no parent)
     */
    public function scopeRootLevel(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Scope for items in a specific folder
     */
    public function scopeInFolder(Builder $query, ?int $folderId): Builder
    {
        if ($folderId) {
            return $query->where('parent_id', $folderId);
        }
        return $query->whereNull('parent_id');
    }

    /* ==================== Helper Methods ==================== */

    /**
     * Check if document is publicly accessible
     */
    public function isPublic(): bool
    {
        return $this->visibility === 'public'
            && $this->published_at
            && $this->published_at->isPast();
    }

    /**
     * Check if user can edit this document
     */
    public function canEdit(?int $userId = null): bool
    {
        $userId = $userId ?? auth()->id();
        if (!$userId) return false;

        // Creator can always edit
        if ($this->uploaded_by === $userId || $this->created_by === $userId) {
            return true;
        }

        // Org admins can edit
        $userRole = $this->organization->getUserRole($userId);
        return in_array($userRole, ['admin', 'owner']);
    }

    /**
     * Check if user can delete this document
     */
    public function canDelete(?int $userId = null): bool
    {
        $userId = $userId ?? auth()->id();
        if (!$userId) return false;

        // Creator can delete their own uploads
        if ($this->uploaded_by === $userId) {
            return true;
        }

        // Org admins can delete
        $userRole = $this->organization->getUserRole($userId);
        return in_array($userRole, ['admin', 'owner']);
    }

    /**
     * Get formatted file size
     */
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

    /**
     * Append can_edit attribute
     */
    public function getCanEditAttribute(): bool
    {
        return $this->canEdit();
    }

    /**
     * Append can_delete attribute
     */
    public function getCanDeleteAttribute(): bool
    {
        return $this->canDelete();
    }

    /**
     * Get breadcrumb path for nested folders
     */
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
}
