<?php

namespace App\Http\Controllers\Review;

use App\Http\Controllers\Controller;
use App\Models\{ReviewRequest, ReviewRecipient, ReviewAction};
use Illuminate\Http\Request;
use App\Enums\ReviewStatus;
use App\Services\ActivityLogger;

class ReviewRecipientController extends Controller
{
    public function update(Request $req, ReviewRequest $review, ReviewRecipient $recipient)
    {
        $this->authorize('update', $review);

        // Check permission
        if (!$req->user()->hasPermission($review->publisher_org_id, 'assign_reviewers')) {
            return response()->json(['message' => 'You do not have permission to update reviewers'], 403);
        }

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
            ActivityLogger::log(
                $review->publisher_org_id,
                'due_date_updated',
                subjectType: 'ReviewRecipient',
                subjectId: $recipient->id,
                metadata: [
                    'reviewer_name' => $recipient->reviewer->name ?? 'Unknown',
                    'old_due_at' => $oldDueAt,
                    'new_due_at' => $data['due_at'],
                ],
                description: auth()->user()->name . " updated due date for {$recipient->reviewer->name}"
            );
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

        ActivityLogger::log(
            $review->publisher_org_id,
            'document_viewed',
            subjectType: 'ReviewRecipient',
            subjectId: $recipient->id,
            metadata: ['review_id' => $review->id],
            description: auth()->user()->name . " viewed the document"
        );

        return response()->noContent();
    }

    // Reviewer approves
    public function approve(ReviewRequest $review, ReviewRecipient $recipient)
    {
        $this->authorize('actAsRecipient', [$review, $recipient]);

        $recipient->update(['status' => 'approved']);

        ActivityLogger::log(
            $review->publisher_org_id,
            'review_approved',
            subjectType: 'ReviewRecipient',
            subjectId: $recipient->id,
            metadata: ['review_id' => $review->id],
            description: auth()->user()->name . " approved the review"
        );

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

        $reason = $req->input('reason');

        ActivityLogger::log(
            $review->publisher_org_id,
            'review_declined',
            subjectType: 'ReviewRecipient',
            subjectId: $recipient->id,
            metadata: ['review_id' => $review->id, 'reason' => $reason],
            description: auth()->user()->name . " declined the review" . ($reason ? ": {$reason}" : '')
        );

        $review->update(['status' => ReviewStatus::Declined->value]);

        return response()->json(['message' => 'Declined.']);
    }

    // Publisher can send reminder to a specific recipient
    public function remind(ReviewRequest $review, ReviewRecipient $recipient)
    {
        $this->authorize('remind', [$review, $recipient]);

        // Check permission
        if (!request()->user()->hasPermission($review->publisher_org_id, 'manage_reviews')) {
            return response()->json(['message' => 'You do not have permission to send reminders'], 403);
        }

        ActivityLogger::log(
            $review->publisher_org_id,
            'reminder_sent',
            subjectType: 'ReviewRecipient',
            subjectId: $recipient->id,
            metadata: [
                'review_id' => $review->id,
                'recipient_name' => $recipient->reviewer->name ?? 'Unknown',
            ],
            description: auth()->user()->name . " sent a reminder to {$recipient->reviewer->name}"
        );

        // (Optional) send notification/email
        return response()->json(['message' => 'Reminder sent']);
    }
}
