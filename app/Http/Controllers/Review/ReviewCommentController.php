<?php

namespace App\Http\Controllers\Review;

use App\Http\Controllers\Controller;
use App\Models\{ReviewRequest, ReviewComment, ReviewRecipient, Organization};
use Illuminate\Http\Request;
use App\Services\ActivityLogger;

class ReviewCommentController extends Controller
{
    /**
     * UNIFIED method for listing comments (works for both Org-Scoped AND Global)
     * Handles: /api/org/{org}/reviews/{review}/comments AND /api/reviews/{review}/comments
     */
    public function index(Request $request, ?Organization $organization, ReviewRequest $review)
    {
        // Authorization: Check if user has access to this review
        $this->authorizeReviewAccess($review, $organization);

        $comments = $review->comments()
            ->with(['author:id,name,email,avatar,avatar_url', 'attachments'])
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($comment) {
                return [
                    'id' => $comment->id,
                    'body' => $comment->body,
                    'is_internal' => $comment->is_internal,
                    'parent_id' => $comment->parent_id,
                    'created_at' => $comment->created_at,
                    'updated_at' => $comment->updated_at,
                    'author' => $comment->author ? [
                        'id' => $comment->author->id,
                        'name' => $comment->author->name,
                        'email' => $comment->author->email,
                        'avatar' => $comment->author->avatar ?? $comment->author->avatar_url,
                    ] : null,
                    'author_user_id' => $comment->author_user_id,
                    'author_org_id' => $comment->author_org_id,
                    'attachments' => $comment->attachments,
                ];
            });

        return response()->json($comments);
    }

    /**
     * UNIFIED method for posting comments (works for both contexts)
     */
    public function store(Request $req, ?Organization $organization, ReviewRequest $review)
    {
        // Authorization: Check if user has access to this review
        $this->authorizeReviewAccess($review, $organization);

        $data = $req->validate([
            'body' => ['required', 'string', 'max:10000'],
            'parent_id' => ['nullable', 'exists:review_comments,id'],
            'is_internal' => ['sometimes', 'boolean'],
            'attachments.*' => ['file', 'max:20480']
        ]);

        // Determine user's organization context
        $userOrg = $req->user()->organizations()->first();

        $comment = $review->comments()->create([
            'author_user_id' => auth()->id(),
            'author_org_id'  => $userOrg->id ?? null,
            'body' => $data['body'],
            'is_internal' => (bool)($data['is_internal'] ?? false),
            'parent_id' => $data['parent_id'] ?? null,
        ]);

        // Handle attachments
        if ($req->hasFile('attachments')) {
            foreach ($req->file('attachments') as $file) {
                $path = $file->store("reviews/{$review->id}/comments/{$comment->id}", 'public');
                $comment->attachments()->create([
                    'uploaded_by' => auth()->id(),
                    'file_path' => $path,
                    'label' => $file->getClientOriginalName()
                ]);
            }
        }

        // Log activity
        ActivityLogger::log(
            $review->publisher_org_id,
            'comment_posted',
            subjectType: 'ReviewComment',
            subjectId: $comment->id,
            metadata: ['review_id' => $review->id],
            description: auth()->user()->name . " commented on review: {$review->subject}"
        );

        return response()->json([
            'id' => $comment->id,
            'body' => $comment->body,
            'is_internal' => $comment->is_internal,
            'parent_id' => $comment->parent_id,
            'created_at' => $comment->created_at,
            'updated_at' => $comment->updated_at,
            'author' => [
                'id' => auth()->user()->id,
                'name' => auth()->user()->name,
                'email' => auth()->user()->email,
                'avatar' => auth()->user()->avatar ?? auth()->user()->avatar_url,
            ],
            'author_user_id' => $comment->author_user_id,
            'author_org_id' => $comment->author_org_id,
            'attachments' => $comment->attachments,
        ], 201);
    }

    /**
     * Private thread comments (Submitter <-> Specific Reviewer)
     */
    public function recipientComments(Request $request, ?Organization $organization, ReviewRequest $review, ReviewRecipient $recipient)
    {
        // Verify recipient belongs to this review
        if ($recipient->review_request_id !== $review->id) {
            abort(404, 'Recipient not found for this review');
        }

        // Authorization: Only submitter or this specific reviewer can access
        $userId = auth()->id();
        $isSubmitter = $review->submitted_by === $userId;
        $isRecipient = $recipient->reviewer_user_id === $userId;

        if (!$isSubmitter && !$isRecipient) {
            abort(403, 'Unauthorized to view this private conversation');
        }

        $publisherId = $review->submitted_by;

        // Get comments between submitter and this reviewer only
        $comments = $review->comments()
            ->where(function ($q) use ($recipient, $publisherId) {
                $q->where('author_user_id', $recipient->reviewer_user_id)
                    ->orWhere('author_user_id', $publisherId);
            })
            ->with(['author:id,name,email,avatar,avatar_url', 'attachments'])
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($comment) {
                return [
                    'id' => $comment->id,
                    'body' => $comment->body,
                    'is_internal' => $comment->is_internal,
                    'parent_id' => $comment->parent_id,
                    'created_at' => $comment->created_at,
                    'updated_at' => $comment->updated_at,
                    'author' => $comment->author ? [
                        'id' => $comment->author->id,
                        'name' => $comment->author->name,
                        'email' => $comment->author->email,
                        'avatar' => $comment->author->avatar ?? $comment->author->avatar_url,
                    ] : null,
                    'author_user_id' => $comment->author_user_id,
                    'author_org_id' => $comment->author_org_id,
                    'attachments' => $comment->attachments,
                ];
            });

        return response()->json($comments);
    }

    /**
     * Post comment in private thread
     */
    public function storeRecipientComment(Request $req, ?Organization $organization, ReviewRequest $review, ReviewRecipient $recipient)
    {
        // Verify recipient belongs to this review
        if ($recipient->review_request_id !== $review->id) {
            abort(404, 'Recipient not found for this review');
        }

        // Only submitter or the recipient can post
        $userId = auth()->id();
        $isSubmitter = $review->submitted_by === $userId;
        $isRecipient = $recipient->reviewer_user_id === $userId;

        if (!$isSubmitter && !$isRecipient) {
            abort(403, 'Unauthorized to post in this conversation');
        }

        $data = $req->validate([
            'body' => ['required', 'string', 'max:10000'],
            'attachments.*' => ['file', 'max:20480']
        ]);

        $userOrg = $req->user()->organizations()->first();

        $comment = $review->comments()->create([
            'author_user_id' => $userId,
            'author_org_id'  => $userOrg->id ?? null,
            'body' => $data['body'],
            'is_internal' => false,
            'parent_id' => null,
        ]);

        if ($req->hasFile('attachments')) {
            foreach ($req->file('attachments') as $file) {
                $path = $file->store("reviews/{$review->id}/comments/{$comment->id}", 'public');
                $comment->attachments()->create([
                    'uploaded_by' => $userId,
                    'file_path' => $path,
                    'label' => $file->getClientOriginalName()
                ]);
            }
        }

        ActivityLogger::log(
            $review->publisher_org_id,
            'private_message_sent',
            subjectType: 'ReviewComment',
            subjectId: $comment->id,
            metadata: ['review_id' => $review->id, 'recipient_id' => $recipient->id],
            description: auth()->user()->name . " sent a private message"
        );

        return response()->json([
            'id' => $comment->id,
            'body' => $comment->body,
            'is_internal' => $comment->is_internal,
            'parent_id' => $comment->parent_id,
            'created_at' => $comment->created_at,
            'updated_at' => $comment->updated_at,
            'author' => [
                'id' => $userId,
                'name' => $req->user()->name,
                'email' => $req->user()->email,
                'avatar' => $req->user()->avatar ?? $req->user()->avatar_url,
            ],
            'author_user_id' => $comment->author_user_id,
            'author_org_id' => $comment->author_org_id,
            'attachments' => $comment->attachments,
        ], 201);
    }

    /**
     * Helper: Unified authorization check
     * Allows access if user is EITHER the submitter OR a recipient
     */
    private function authorizeReviewAccess(ReviewRequest $review, ?Organization $organization): void
    {
        $userId = auth()->id();

        // Check if user is the submitter
        $isSubmitter = $review->submitted_by === $userId;

        // Check if user is a recipient (in any org)
        $isRecipient = $review->recipients()
            ->where('reviewer_user_id', $userId)
            ->exists();

        // If organization context provided, verify it matches
        if ($organization) {
            $belongsToOrg = $review->publisher_org_id === $organization->id ||
                $review->recipients()->where('reviewer_org_id', $organization->id)->exists();

            if (!$belongsToOrg) {
                abort(403, 'Review not accessible in this organization context');
            }
        }

        // User must be either submitter or recipient
        if (!$isSubmitter && !$isRecipient) {
            abort(403, 'You do not have access to this review');
        }
    }
}
