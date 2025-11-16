<?php

namespace App\Models;

use App\Enums\RecipientStatus;
use Illuminate\Database\Eloquent\Model;

// app/Models/ReviewRecipient.php
class ReviewRecipient extends Model
{
    protected $fillable = ['review_request_id', 'reviewer_user_id', 'reviewer_org_id', 'status', 'due_at', 'last_viewed_at'];
    protected $casts = [
        'due_at' => 'datetime',
        'last_viewed_at' => 'datetime',
        'status' => RecipientStatus::class,
    ];

    public function request()
    {
        return $this->belongsTo(ReviewRequest::class, 'review_request_id');
    }
    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }
    public function org()
    {
        return $this->belongsTo(Organization::class, 'reviewer_org_id');
    }


    public function markApproved(int $actorUserId): void
    {
        if ($this->status->isFinal()) return;
        $this->update(['status' => RecipientStatus::Approved]);
        $this->request->actions()->create([
            'actor_user_id' => $actorUserId,
            'actor_org_id'  => $this->reviewer_org_id,
            'action'        => 'reviewer_approved',
            'meta'          => ['reviewer_user_id' => $this->reviewer_user_id],
        ]);
    }

    public function markDeclined(int $actorUserId, ?string $reason = null): void
    {
        if ($this->status->isFinal()) return;
        $this->update(['status' => RecipientStatus::Declined]);
        $this->request->actions()->create([
            'actor_user_id' => $actorUserId,
            'actor_org_id'  => $this->reviewer_org_id,
            'action'        => 'reviewer_declined',
            'meta'          => ['reviewer_user_id' => $this->reviewer_user_id, 'reason' => $reason],
        ]);
    }
}
