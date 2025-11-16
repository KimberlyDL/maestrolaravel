<?php

namespace App\Http\Controllers\Review;

use App\Http\Controllers\Controller;
use App\Models\{ReviewRequest, ReviewRecipient, ReviewAction};
use Illuminate\Http\Request;
use App\Enums\ReviewStatus;

class ReviewRecipientController extends Controller
{
    public function update(Request $req, ReviewRequest $review, ReviewRecipient $recipient)
    {
        $this->authorize('update', $review);

        // Ensure the recipient belongs to this review
        if ((int) $recipient->review_request_id !== (int) $review->id) {
            return response()->json(['message' => 'Recipient not found for this review'], 404);
        }

        // Validate allowed fields
        $data = $req->validate([
            'due_at' => ['nullable', 'date'],
        ]);

        $oldDueAt = $recipient->due_at;

        // Apply updates
        if (array_key_exists('due_at', $data)) {
            $recipient->due_at = $data['due_at'];
        }

        $recipient->save();

        // Log the action if due date changed
        if ($oldDueAt != $data['due_at']) {
            $review->actions()->create([
                'actor_user_id' => auth()->id(),
                'actor_org_id' => $review->publisher_org_id,
                'action' => 'due_date_updated',
                'meta' => [
                    'reviewer_name' => $recipient->reviewer->name ?? 'Unknown',
                    'old_due_at' => $oldDueAt,
                    'new_due_at' => $data['due_at'],
                ],
            ]);
        }

        // Return the updated recipient with useful relations
        $recipient->load(['reviewer:id,name,email,avatar,avatar_url', 'org:id,name']);

        return response()->json([
            'id' => $recipient->id,
            'review_request_id' => $recipient->review_request_id,
            'reviewer_user_id' => $recipient->reviewer_user_id,
            'reviewer_org_id' => $recipient->reviewer_org_id,
            'status' => $recipient->status,
            'due_at' => $recipient->due_at,
            'last_viewed_at' => $recipient->last_viewed_at,
            'reviewer' => $recipient->reviewer ? [
                'id' => $recipient->reviewer->id,
                'name' => $recipient->reviewer->name,
                'email' => $recipient->reviewer->email,
                'avatar' => $recipient->reviewer->avatar ?? $recipient->reviewer->avatar_url,
            ] : null,
            'org' => $recipient->org ? [
                'id' => $recipient->org->id,
                'name' => $recipient->org->name,
            ] : null,
        ]);
    }

    // Reviewer marks "viewed"
    public function markViewed(ReviewRequest $review, ReviewRecipient $recipient)
    {
        $this->authorize('actAsRecipient', [$review, $recipient]);

        $recipient->update([
            'status' => $recipient->status === 'pending' ? 'viewed' : $recipient->status,
            'last_viewed_at' => now()
        ]);

        $review->actions()->create([
            'actor_user_id' => auth()->id(),
            'actor_org_id' => $recipient->reviewer_org_id,
            'action' => 'viewed',
        ]);

        return response()->noContent();
    }

    // Reviewer approves
    public function approve(ReviewRequest $review, ReviewRecipient $recipient)
    {
        $this->authorize('actAsRecipient', [$review, $recipient]);

        $recipient->update(['status' => 'approved']);

        $review->actions()->create([
            'actor_user_id' => auth()->id(),
            'actor_org_id' => $recipient->reviewer_org_id,
            'action' => 'approved',
        ]);

        // Optional: if all recipients approved, auto-advance review status
        if ($review->recipients()->whereNot('status', 'approved')->exists() === false) {
            $review->update(['status' => ReviewStatus::Approved->value]);
        } else {
            $review->update(['status' => ReviewStatus::InReview->value]);
        }

        return response()->json(['message' => 'Approved.']);
    }

    // Reviewer declines
    public function decline(ReviewRequest $review, ReviewRecipient $recipient, Request $req)
    {
        $this->authorize('actAsRecipient', [$review, $recipient]);

        $recipient->update(['status' => 'declined']);

        $review->actions()->create([
            'actor_user_id' => auth()->id(),
            'actor_org_id' => $recipient->reviewer_org_id,
            'action' => 'declined',
            'meta' => ['reason' => $req->input('reason')]
        ]);

        $review->update(['status' => ReviewStatus::Declined->value]);

        return response()->json(['message' => 'Declined.']);
    }

    // Publisher can send reminder to a specific recipient
    public function remind(ReviewRequest $review, ReviewRecipient $recipient)
    {
        $this->authorize('remind', [$review, $recipient]);

        $review->actions()->create([
            'actor_user_id' => auth()->id(),
            'actor_org_id' => $review->publisher_org_id,
            'action' => 'reminded',
            'meta' => [
                'recipient_id' => $recipient->id,
                'recipient_name' => $recipient->reviewer->name ?? 'Unknown',
            ]
        ]);

        // (Optional) send notification/email
        return response()->json(['message' => 'Reminder sent']);
    }
}
