<?php

namespace App\Http\Controllers\Review;

use App\Http\Controllers\Controller;
use App\Models\{ReviewRequest, ReviewRecipient, Document, DocumentVersion, ReviewAction};
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Enums\ReviewStatus;

class ReviewRequestController extends Controller
{
    // // List reviews (both POV: publisher-created OR reviewer-assigned)
    // public function index(Request $req)
    // {
    //     $filter = $req->query('filter'); // e.g., 'as_publisher', 'as_reviewer', 'status'
    //     $query = ReviewRequest::query()
    //         ->with(['document', 'version', 'publisher', 'recipients.reviewer']);

    //     if ($filter === 'as_publisher') {
    //         $query->where('submitted_by', auth()->id());
    //     } elseif ($filter === 'as_reviewer') {
    //         $query->whereHas('recipients', fn($q) => $q->where('reviewer_user_id', auth()->id()));
    //     }

    //     if ($status = $req->query('status')) {
    //         $query->where('status', $status);
    //     }

    //     return $query->latest()->paginate(15);
    // }

    // ReviewRequestController@index
    public function index(Request $req)
    {
        $filter = $req->query('filter'); // 'as_publisher' | 'as_reviewer'
        $query = ReviewRequest::query()
            ->with(['document', 'version', 'publisher', 'recipients.reviewer']);

        if ($filter === 'as_publisher') {
            $query->where('submitted_by', auth()->id());
        } elseif ($filter === 'as_reviewer') {
            $query->whereHas('recipients', fn($q) => $q->where('reviewer_user_id', auth()->id()));
        }

        if ($status = $req->query('status')) {
            $query->where('status', $status);
        }

        // NEW: filter by publisher org
        if ($orgId = $req->integer('publisher_org_id')) {
            $query->where('publisher_org_id', $orgId);
        }

        // OPTIONAL: simple search over subject, document title
        if ($q = $req->query('q')) {
            $query->where(function ($q2) use ($q) {
                $q2->where('subject', 'like', "%{$q}%")
                    ->orWhereHas('document', fn($qd) => $qd->where('title', 'like', "%{$q}%"));
            });
        }

        // If you want reviewer-side default sort by updated_at desc:
        return $query->orderByDesc('updated_at')->paginate(15);
    }

    // Submit for review
    public function store(Request $req)
    {
        $data = $req->validate([
            'document_id' => ['required', 'exists:documents,id'],
            'document_version_id' => ['nullable', 'exists:document_versions,id'],
            'publisher_org_id' => ['required', 'exists:organizations,id'],
            'subject' => ['required', 'string', 'max:255'],
            'body'    => ['nullable', 'string', 'max:5000'],
            'due_at'  => ['nullable', 'date'],
            'recipients' => ['required', 'array', 'min:1'],
            'recipients.*.user_id' => ['required', 'exists:users,id'],
            'recipients.*.org_id'  => ['nullable', 'exists:organizations,id'],
            'recipients.*.due_at'  => ['nullable', 'date'],
            'attachments.*' => ['file', 'max:20480']
        ]);

        // Authorization: submitter must be member of publisher_org and can submit this document
        $document = Document::with('latestVersion')->findOrFail($data['document_id']);
        $this->authorize('submitForReview', [$document, $data['publisher_org_id']]);

        // Pick version
        $versionId = $data['document_version_id'] ?? $document->latest_version_id;

        $review = ReviewRequest::create([
            'document_id' => $document->id,
            'document_version_id' => $versionId,
            'publisher_org_id' => $data['publisher_org_id'],
            'submitted_by' => auth()->id(),
            'subject' => $data['subject'],
            'body'    => $data['body'] ?? null,
            'status'  => ReviewStatus::Sent->value, // directly sent; preflight can switch to Draft
            'due_at'  => $data['due_at'] ?? null,
        ]);

        foreach ($data['recipients'] as $r) {
            ReviewRecipient::create([
                'review_request_id' => $review->id,
                'reviewer_user_id'  => $r['user_id'],
                'reviewer_org_id'   => $r['org_id'] ?? null,
                'status'            => 'pending',
                'due_at'            => $r['due_at'] ?? null,
            ]);
        }

        // Save attachments if any
        if ($req->hasFile('attachments')) {
            foreach ($req->file('attachments') as $file) {
                $path = $file->store("reviews/{$review->id}/attachments", 'public');
                $review->attachments()->create([
                    'uploaded_by' => auth()->id(),
                    'file_path'   => $path,
                    'label'       => $file->getClientOriginalName()
                ]);
            }
        }

        $review->actions()->create([
            'actor_user_id' => auth()->id(),
            'actor_org_id'  => $data['publisher_org_id'],
            'action'        => 'sent',
            'meta'          => null,
        ]);

        // (Optional) dispatch notifications to recipients
        // ReviewSubmitted::dispatch($review);

        return response()->json($review->load(['recipients.reviewer', 'document', 'version']), 201);
    }

    public function show(ReviewRequest $review)
    {
        $this->authorize('view', $review);
        return $review->load([
            'document',
            'version',
            'publisher',
            'recipients.reviewer',
            'comments.author',
            'attachments'
        ]);
    }

    // Publisher: update meta (add recipients, change due date)
    public function update(Request $req, ReviewRequest $review)
    {
        $this->authorize('update', $review);

        $data = $req->validate([
            'subject' => ['sometimes', 'string', 'max:255'],
            'body'    => ['sometimes', 'nullable', 'string', 'max:5000'],
            'due_at'  => ['sometimes', 'nullable', 'date'],
            'add_recipients' => ['sometimes', 'array'],
            'add_recipients.*.user_id' => ['required', 'exists:users,id'],
            'add_recipients.*.org_id'  => ['nullable', 'exists:organizations,id'],
            'add_recipients.*.due_at'  => ['nullable', 'date'],
        ]);

        $review->fill($req->only('subject', 'body', 'due_at'))->save();

        if (!empty($data['add_recipients'])) {
            foreach ($data['add_recipients'] as $r) {
                ReviewRecipient::firstOrCreate([
                    'review_request_id' => $review->id,
                    'reviewer_user_id'  => $r['user_id'],
                ], [
                    'reviewer_org_id'   => $r['org_id'] ?? null,
                    'status'            => 'pending',
                    'due_at'            => $r['due_at'] ?? null,
                ]);
            }
            $review->actions()->create([
                'actor_user_id' => auth()->id(),
                'actor_org_id'  => $review->publisher_org_id,
                'action'        => 'reassigned',
                'meta'          => null,
            ]);
        }

        return $review->load('recipients.reviewer');
    }

    // Publisher: close/reopen
    public function close(ReviewRequest $review)
    {
        $this->authorize('close', $review);
        $review->update(['status' => ReviewStatus::Closed->value]);

        $review->actions()->create([
            'actor_user_id' => auth()->id(),
            'actor_org_id'  => $review->publisher_org_id,
            'action'        => 'closed',
            'meta'          => null,
        ]);

        return response()->json(['message' => 'Review closed']);
    }

    public function reopen(ReviewRequest $review)
    {
        $this->authorize('reopen', $review);
        $review->update(['status' => ReviewStatus::InReview->value]);

        $review->actions()->create([
            'actor_user_id' => auth()->id(),
            'actor_org_id'  => $review->publisher_org_id,
            'action'        => 'reopened',
            'meta'          => null,
        ]);

        return response()->json(['message' => 'Review reopened']);
    }

    // Publisher: attach a NEW document version to the ongoing review thread
    public function attachNewVersion(Request $req, ReviewRequest $review)
    {
        $this->authorize('attachVersion', $review);

        $data = $req->validate([
            'file' => ['required', 'file', 'max:20480'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $document = $review->document()->with('versions')->first();
        $next = ($document->versions()->max('version_number') ?? 0) + 1;
        $path = $data['file']->store("documents/{$document->id}", 'public');

        $ver = $document->versions()->create([
            'version_number' => $next,
            'file_path' => $path,
            'note' => $data['note'] ?? null,
            'uploaded_by' => auth()->id(),
        ]);

        $document->update(['latest_version_id' => $ver->id]);
        $review->update(['document_version_id' => $ver->id]);

        $review->actions()->create([
            'actor_user_id' => auth()->id(),
            'actor_org_id'  => $review->publisher_org_id,
            'action'        => 'version_uploaded',
            'meta'          => ['version' => $ver->version_number],
        ]);

        // (Optional) notify recipients “New version uploaded”
        return $review->load('version');
    }

    /**
     * Get activity log for a review
     */
    public function getActivityLog(ReviewRequest $review)
    {
        $this->authorize('view', $review);

        $activities = $review->actions()
            ->with(['actor:id,name,email,avatar,avatar_url', 'actorOrg:id,name'])
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($action) {
                return [
                    'id' => $action->id,
                    'action' => $action->action,
                    'meta' => $action->meta,
                    'created_at' => $action->created_at,
                    'actor' => $action->actor ? [
                        'id' => $action->actor->id,
                        'name' => $action->actor->name,
                        'email' => $action->actor->email,
                        'avatar' => $action->actor->avatar ?? $action->actor->avatar_url,
                    ] : null,
                    'actor_org' => $action->actorOrg ? [
                        'id' => $action->actorOrg->id,
                        'name' => $action->actorOrg->name,
                    ] : null,
                ];
            });

        return response()->json($activities);
    }

    /**
     * Remove a recipient from a review
     */
    public function removeRecipient(ReviewRequest $review, ReviewRecipient $recipient)
    {
        $this->authorize('update', $review);

        // Ensure the recipient belongs to this review
        if ($recipient->review_request_id !== $review->id) {
            return response()->json(['message' => 'Recipient not found for this review'], 404);
        }

        // Don't allow removing if they've already approved/declined
        if (in_array($recipient->status->value, ['approved', 'declined'])) {
            return response()->json([
                'message' => 'Cannot remove reviewer who has already responded'
            ], 422);
        }

        $recipientName = $recipient->reviewer->name ?? 'Unknown';
        $recipient->delete();

        // Log the action
        $review->actions()->create([
            'actor_user_id' => auth()->id(),
            'actor_org_id' => $review->publisher_org_id,
            'action' => 'reviewer_removed',
            'meta' => ['reviewer_name' => $recipientName],
        ]);

        return response()->json(['message' => 'Reviewer removed successfully']);
    }
}
