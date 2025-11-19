<?php
// app/Models/DocumentShare.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentShare extends Model
{
    protected $table = 'document_shares';

    protected $fillable = [
        'document_id',
        'share_token',
        'access_level', // 'org_only', 'link', 'public'
        'created_by',
        'updated_by',
        'revoked_by',
        'expires_at',
        'revoked_at',
        'password',
        'max_downloads',
        'download_count',
        'allowed_ips',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $hidden = ['password']; // Never expose password hash in API
    protected $appends = ['is_password_protected', 'has_download_limit'];

    /* ==================== RELATIONSHIPS ==================== */

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function revokedBy()
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function accessLogs()
    {
        return $this->hasMany(DocumentShareAccessLog::class);
    }

    /* ==================== ACCESSORS ==================== */

    public function getIsPasswordProtectedAttribute(): bool
    {
        return !empty($this->password);
    }

    public function getHasDownloadLimitAttribute(): bool
    {
        return !empty($this->max_downloads);
    }

    /* ==================== METHODS ==================== */

    /**
     * Check if share link is valid
     */
    public function isValid(): bool
    {
        // Check if revoked
        if ($this->revoked_at) {
            return false;
        }

        // Check if expired
        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Check if download limit is reached
     */
    public function isDownloadLimitReached(): bool
    {
        return $this->has_download_limit
            && $this->download_count >= $this->max_downloads;
    }

    /**
     * Get remaining downloads
     */
    public function getRemainingDownloads(): ?int
    {
        if (!$this->has_download_limit) {
            return null;
        }

        return max(0, $this->max_downloads - $this->download_count);
    }

    /**
     * Get time until expiry
     */
    public function getTimeUntilExpiry(): ?string
    {
        if (!$this->expires_at) {
            return null;
        }

        $now = now();
        if ($this->expires_at->isPast()) {
            return 'Expired';
        }

        $diff = $this->expires_at->diffForHumans($now);
        return "Expires {$diff}";
    }

    /**
     * Get last accessed timestamp
     */
    public function getLastAccessed()
    {
        return $this->accessLogs()
            ->where('success', true)
            ->latest()
            ->value('created_at');
    }

    /**
     * Revoke the share
     */
    public function revoke(?int $userId = null): void
    {
        $this->update([
            'access_level' => 'org_only',
            'password' => null,
            'revoked_at' => now(),
            'revoked_by' => $userId ?? auth()->id(),
        ]);
    }

    /**
     * Check if share is for organization only
     */
    public function isOrgOnly(): bool
    {
        return $this->access_level === 'org_only';
    }

    /**
     * Check if share is via link
     */
    public function isLinkShare(): bool
    {
        return $this->access_level === 'link';
    }

    /**
     * Check if share is public
     */
    public function isPublic(): bool
    {
        return $this->access_level === 'public';
    }
}

// ============================================================
// app/Models/DocumentShareAccessLog.php
// ============================================================

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentShareAccessLog extends Model
{
    protected $table = 'document_share_access_logs';

    protected $fillable = [
        'share_id',
        'document_id',
        'type',
        'success',
        'ip_address',
        'user_agent',
        'referrer',
    ];

    protected $casts = [
        'success' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public $timestamps = true;
    public $incrementing = true;

    /* ==================== RELATIONSHIPS ==================== */

    public function share()
    {
        return $this->belongsTo(DocumentShare::class);
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    /* ==================== SCOPES ==================== */

    public function scopeSuccessful($query)
    {
        return $query->where('success', true);
    }

    public function scopeFailed($query)
    {
        return $query->where('success', false);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeRecentHours($query, int $hours = 24)
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }
}
