<?php

namespace App\Http\Controllers\Review;

use App\Http\Controllers\Controller;
use App\Models\{ReviewRequest, ReviewComment, ReviewRecipient, ReviewAttachment};
use Illuminate\Http\Request;

class ReviewCommentController extends Controller
{
    public function index(ReviewRequest $review)
    {
        $this->authorize('view', $review);

        $comments = $review->comments()
            ->with(['author:id,name,email,avatar,avatar_url', 'attachments'])
            ->latest()
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

    public function store(Request $req, ReviewRequest $review)
    {
        $this->authorize('comment', $review);

        $data = $req->validate([
            'body' => ['required', 'string', 'max:10000'],
            'parent_id' => ['nullable', 'exists:review_comments,id'],
            'is_internal' => ['sometimes', 'boolean'],
            'attachments.*' => ['file', 'max:20480']
        ]);

        $comment = $review->comments()->create([
            'author_user_id' => auth()->id(),
            'author_org_id'  => $req->user()->organizations()->first()->id ?? null,
            'body' => $data['body'],
            'is_internal' => (bool)($data['is_internal'] ?? false),
            'parent_id' => $data['parent_id'] ?? null,
        ]);

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

        $review->actions()->create([
            'actor_user_id' => auth()->id(),
            'actor_org_id'  => $comment->author_org_id,
            'action'        => 'commented'
        ]);

        // Load author relationship before returning
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

    public function recipientComments(ReviewRequest $review, ReviewRecipient $recipient)
    {
        // Must be able to view the review
        $this->authorize('view', $review);

        // Ensure the recipient belongs to this review thread
        if ($recipient->review_request_id !== $review->id) {
            abort(404, 'Recipient not found for this review');
        }

        // Filter comments authored by this recipient's user account
        $comments = $review->comments()
            ->where('author_user_id', $recipient->reviewer_user_id) // <-- correct column
            ->with(['author:id,name,email,avatar,avatar_url', 'attachments'])
            ->latest()
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
}
