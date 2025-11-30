<?php

namespace App\Models;

use App\Enums\RecipientStatus;
use App\Enums\ReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ReviewRequest extends Model
{
    protected $fillable = [
        'document_id',
        'document_version_id',
        'publisher_org_id',
        'submitted_by',
        'subject',
        'body',
        'status',
        'approval_status',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'rejected_by',
        'rejected_at',
        'due_at'
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'status' => ReviewStatus::class,
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    protected $appends = ['needs_approval', 'can_be_sent'];

    /* ==================== Relationships ==================== */

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function version()
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    public function publisher()
    {
        return $this->belongsTo(Organization::class, 'publisher_org_id');
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function recipients()
    {
        return $this->hasMany(ReviewRecipient::class);
    }

    public function comments()
    {
        return $this->hasMany(ReviewComment::class);
    }

    public function attachments()
    {
        return $this->hasMany(ReviewAttachment::class);
    }

    public function actions()
    {
        return $this->hasMany(ReviewAction::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejector()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /* ==================== Approval Workflow ==================== */

    public function getNeedsApprovalAttribute(): bool
    {
        return $this->approval_status === 'pending' && $this->status === ReviewStatus::Sent;
    }

    public function getCanBeSentAttribute(): bool
    {
        return $this->approval_status === 'approved' || $this->status === ReviewStatus::Draft;
    }

    public function isApproved(): bool
    {
        return $this->approval_status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->approval_status === 'rejected';
    }

    public function isPendingApproval(): bool
    {
        return $this->approval_status === 'pending';
    }

    public function approve(int $adminUserId): void
    {
        DB::transaction(function () use ($adminUserId) {
            $this->update([
                'approval_status' => 'approved',
                'approved_by' => $adminUserId,
                'approved_at' => now(),
                'rejection_reason' => null,
                'rejected_by' => null,
                'rejected_at' => null,
            ]);

            $this->actions()->create([
                'actor_user_id' => $adminUserId,
                'actor_org_id' => $this->publisher_org_id,
                'action' => 'approved_by_admin',
                'meta' => ['timestamp' => now()->toIso8601String()],
            ]);
        });
    }

    public function reject(int $adminUserId, ?string $reason = null): void
    {
        DB::transaction(function () use ($adminUserId, $reason) {
            $this->update([
                'approval_status' => 'rejected',
                'rejection_reason' => $reason,
                'rejected_by' => $adminUserId,
                'rejected_at' => now(),
                // 'status' => ReviewStatus::Draft, // REMOVED: Do not reset to draft so it stays locked
            ]);

            $this->actions()->create([
                'actor_user_id' => $adminUserId,
                'actor_org_id' => $this->publisher_org_id,
                'action' => 'rejected_by_admin',
                'meta' => ['reason' => $reason, 'timestamp' => now()->toIso8601String()],
            ]);
        });
    }

    /* ==================== Status Transitions ==================== */

    public function send(int $actorUserId, ?int $actorOrgId = null): void
    {
        DB::transaction(function () use ($actorUserId, $actorOrgId) {
            if ($this->status !== ReviewStatus::Draft) {
                throw new \RuntimeException('Only draft requests can be sent.');
            }

            $this->update([
                'status' => ReviewStatus::Sent,
                'approval_status' => 'pending', // Requires admin approval
            ]);

            // Reset recipients to pending
            $this->recipients()->update(['status' => RecipientStatus::Pending]);

            $this->actions()->create([
                'actor_user_id' => $actorUserId,
                'actor_org_id' => $actorOrgId,
                'action' => 'sent_for_approval',
                'meta' => null,
            ]);
        });
    }

    public function markViewedByReviewer(int $reviewerUserId): void
    {
        $rec = $this->recipients()->where('reviewer_user_id', $reviewerUserId)->first();
        if (!$rec) return;

        if ($rec->status === RecipientStatus::Pending) {
            $rec->update([
                'status' => RecipientStatus::Viewed,
                'last_viewed_at' => now(),
            ]);
            $this->actions()->create([
                'actor_user_id' => $reviewerUserId,
                'actor_org_id' => $rec->reviewer_org_id,
                'action' => 'viewed',
                'meta' => ['reviewer_user_id' => $reviewerUserId],
            ]);
        }
    }

    public function requestChanges(int $actorUserId, ?int $actorOrgId = null, ?string $note = null): void
    {
        DB::transaction(function () use ($actorUserId, $actorOrgId, $note) {
            if ($this->status->isFinal()) {
                throw new \RuntimeException('Cannot request changes on a finalized thread.');
            }
            $this->update(['status' => ReviewStatus::ChangesRequested]);
            $this->actions()->create([
                'actor_user_id' => $actorUserId,
                'actor_org_id' => $actorOrgId,
                'action' => 'requested_changes',
                'meta' => ['note' => $note],
            ]);
        });
    }

    public function close(int $actorUserId, ?int $actorOrgId = null): void
    {
        DB::transaction(function () use ($actorUserId, $actorOrgId) {
            if ($this->status->isFinal()) {
                throw new \RuntimeException('Already finalized.');
            }
            $this->update(['status' => ReviewStatus::Closed]);
            $this->actions()->create([
                'actor_user_id' => $actorUserId,
                'actor_org_id' => $actorOrgId,
                'action' => 'closed',
                'meta' => null,
            ]);
        });
    }

    public function reopen(int $actorUserId, ?int $actorOrgId = null): void
    {
        DB::transaction(function () use ($actorUserId, $actorOrgId) {
            if (!$this->status->isFinal()) {
                throw new \RuntimeException('Only finalized threads can be reopened.');
            }
            $this->update(['status' => ReviewStatus::InReview]);
            $this->actions()->create([
                'actor_user_id' => $actorUserId,
                'actor_org_id' => $actorOrgId,
                'action' => 'reopened',
                'meta' => null,
            ]);
        });
    }
}
