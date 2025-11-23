<?php

namespace App\Http\Controllers\Review;

use App\Http\Controllers\Controller;
use App\Models\{ReviewRequest, ReviewComment, ReviewRecipient, Organization};
use Illuminate\Http\Request;
use App\Services\ActivityLogger;

class ReviewCommentController extends Controller
{
    /**
     * List all comments on a review
     */
    public function index(ReviewRequest $review)
    {
        $this->authorize('view', $review);

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
     * Create a new comment
     */
    public function store(Request $req, ReviewRequest $review)
    {
        $this->authorize('comment', $review);

        // Check permission
        if (!$req->user()->hasPermission($review->publisher_org_id, 'comment_on_reviews')) {
            return response()->json(['message' => 'You do not have permission to comment'], 403);
        }

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
     * Get comments from a specific recipient (private conversation view)
     */
    public function recipientComments(Organization $organization, ReviewRequest $review, ReviewRecipient $recipient)
    {
        $this->authorize('view', $review);

        // Ensure the recipient belongs to this review thread
        if ($recipient->review_request_id !== $review->id) {
            abort(404, 'Recipient not found for this review');
        }

        $publisherId = $review->submitted_by;

        // Get all comments from this specific reviewer
        $comments = $review->comments()
            ->where(function($q) use ($recipient, $publisherId) {
                $q->where('author_user_id', $recipient->reviewer_user_id)
                  ->orWhere('author_user_id', $publisherId);
            })
            ->with(['author:id,name,email,avatar,avatar_url', 'attachments'])
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($comment) {
                return [
                    'id'             => $comment->id,
                    'body'           => $comment->body,
                    'is_internal'    => $comment->is_internal,
                    'parent_id'      => $comment->parent_id,
                    'created_at'     => $comment->created_at,
                    'updated_at'     => $comment->updated_at,
                    'author'         => $comment->author ? [
                        'id'     => $comment->author->id,
                        'name'   => $comment->author->name,
                        'email'  => $comment->author->email,
                        'avatar' => $comment->author->avatar ?? $comment->author->avatar_url,
                    ] : null,
                    'author_user_id' => $comment->author_user_id,
                    'author_org_id'  => $comment->author_org_id,
                    'attachments'    => $comment->attachments,
                ];
            });

        return response()->json($comments);
    }

    /**
     * Post a comment in a private recipient conversation
     */
public function storeRecipientComment(Request $req, Organization $organization, ReviewRequest $review, ReviewRecipient $recipient)
    {
        // $this->authorize('view', $review);

        // Ensure the recipient belongs to this review
        if ($recipient->review_request_id !== $review->id) {
            abort(404, 'Recipient not found for this review');
        }

        // Only publisher or the recipient themselves can post in private conversation
        $user = auth()->user();
        $isPublisher = $review->submitted_by === $user->id;
        $isRecipient = $recipient->reviewer_user_id === $user->id;

        if (!$isPublisher && !$isRecipient) {
            return response()->json(['message' => 'Unauthorized to post in this conversation'], 403);
        }

        $data = $req->validate([
            'body' => ['required', 'string', 'max:10000'],
            'attachments.*' => ['file', 'max:20480']
        ]);

        $userOrg = $user->organizations()->first();

        $comment = $review->comments()->create([
            'author_user_id' => $user->id,
            'author_org_id'  => $userOrg->id ?? null,
            'body' => $data['body'],
            'is_internal' => false,
            'parent_id' => null,
        ]);

        if ($req->hasFile('attachments')) {
            foreach ($req->file('attachments') as $file) {
                $path = $file->store("reviews/{$review->id}/comments/{$comment->id}", 'public');
                $comment->attachments()->create([
                    'uploaded_by' => $user->id,
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
            description: "{$user->name} sent a private message to {$recipient->reviewer->name}"
        );

        return response()->json([
            'id' => $comment->id,
            'body' => $comment->body,
            'is_internal' => $comment->is_internal,
            'parent_id' => $comment->parent_id,
            'created_at' => $comment->created_at,
            'updated_at' => $comment->updated_at,
            'author' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar ?? $user->avatar_url,
            ],
            'author_user_id' => $comment->author_user_id,
            'author_org_id' => $comment->author_org_id,
            'attachments' => $comment->attachments,
        ], 201);
    }
}
