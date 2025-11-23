<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewMessage extends Model
{
    protected $fillable = [
        'review_request_id',
        'sender_user_id',
        'sender_org_id',
        'message',
        'message_type',
        'document_version_id',
        'status_change',
        'read_by',
    ];

    protected $casts = [
        'read_by' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = ['is_read_by_me'];

    /* Relationships */

    public function reviewRequest(): BelongsTo
    {
        return $this->belongsTo(ReviewRequest::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function senderOrg(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'sender_org_id');
    }

    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class);
    }

    /* Helper Methods */

    public function markAsRead(int $userId): void
    {
        $readBy = $this->read_by ?? [];

        if (!in_array($userId, $readBy)) {
            $readBy[] = $userId;
            $this->update(['read_by' => $readBy]);
        }
    }

    public function isReadBy(int $userId): bool
    {
        return in_array($userId, $this->read_by ?? []);
    }

    public function getIsReadByMeAttribute(): bool
    {
        return $this->isReadBy(auth()->id() ?? 0);
    }

    public function getUnreadCount(int $forUserId): int
    {
        return !$this->isReadBy($forUserId) ? 1 : 0;
    }
}
