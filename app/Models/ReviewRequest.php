<?php

namespace App\Models;

use App\Enums\RecipientStatus;
use App\Enums\ReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

// app/Models/ReviewRequest.php
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
        'due_at'
    ];
    protected $casts = [
        'due_at' => 'datetime',
        'status' => ReviewStatus::class,
    ];

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





    public function send(int $actorUserId, ?int $actorOrgId = null): void
    {
        DB::transaction(function () use ($actorUserId, $actorOrgId) {
            if ($this->status !== ReviewStatus::Draft) {
                throw new \RuntimeException('Only draft requests can be sent.');
            }
            $this->update(['status' => ReviewStatus::Sent]);
            // Reset recipients to pending
            $this->recipients()->update(['status' => RecipientStatus::Pending]);
            $this->actions()->create([
                'actor_user_id' => $actorUserId,
                'actor_org_id'  => $actorOrgId,
                'action'        => 'sent',
                'meta'          => null,
            ]);
        });
    }

    public function markViewedByReviewer(int $reviewerUserId): void
    {
        $rec = $this->recipients()->where('reviewer_user_id', $reviewerUserId)->first();
        if (!$rec) return;

        if ($rec->status === RecipientStatus::Pending) {
            $rec->update([
                'status'         => RecipientStatus::Viewed,
                'last_viewed_at' => now(),
            ]);
            $this->actions()->create([
                'actor_user_id' => $reviewerUserId,
                'actor_org_id'  => $rec->reviewer_org_id,
                'action'        => 'viewed',
                'meta'          => ['reviewer_user_id' => $reviewerUserId],
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
                'actor_org_id'  => $actorOrgId,
                'action'        => 'requested_changes',
                'meta'          => ['note' => $note],
            ]);
        });
    }

    public function approve(int $actorUserId, ?int $actorOrgId = null): void
    {
        DB::transaction(function () use ($actorUserId, $actorOrgId) {
            if ($this->status->isFinal()) {
                throw new \RuntimeException('Already finalized.');
            }

            // Option A: whole-thread approve immediately
            $this->update(['status' => ReviewStatus::Approved]);

            // Option B (consensus): mark this reviewer approved first and compute finalization elsewhere

            $this->actions()->create([
                'actor_user_id' => $actorUserId,
                'actor_org_id'  => $actorOrgId,
                'action'        => 'approved',
                'meta'          => null,
            ]);
        });
    }

    public function decline(int $actorUserId, ?int $actorOrgId = null, ?string $reason = null): void
    {
        DB::transaction(function () use ($actorUserId, $actorOrgId, $reason) {
            if ($this->status->isFinal()) {
                throw new \RuntimeException('Already finalized.');
            }
            $this->update(['status' => ReviewStatus::Declined]);
            $this->actions()->create([
                'actor_user_id' => $actorUserId,
                'actor_org_id'  => $actorOrgId,
                'action'        => 'declined',
                'meta'          => ['reason' => $reason],
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
                'actor_org_id'  => $actorOrgId,
                'action'        => 'closed',
                'meta'          => null,
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
            // Optionally reset recipients back to pending
            // $this->recipients()->update(['status' => RecipientStatus::Pending]);
            $this->actions()->create([
                'actor_user_id' => $actorUserId,
                'actor_org_id'  => $actorOrgId,
                'action'        => 'reopened',
                'meta'          => null,
            ]);
        });
    }
}
