<?php

namespace App\Http\Controllers\Review;

use App\Http\Controllers\Controller;
use App\Models\{ReviewRequest, ReviewRecipient, Document, DocumentVersion, Organization};
use Illuminate\Http\Request;
use App\Enums\ReviewStatus;
use App\Services\ActivityLogger;

class ReviewRequestController extends Controller
{
    /**
     * List reviews (organization-scoped)
     */
    // public function index(Request $req, ?Organization $organization)
    // {
    //     $filter = $req->query('filter'); // 'as_publisher' | 'as_reviewer'

    //     $query = ReviewRequest::query()
    //         ->with(['document', 'version', 'publisher', 'recipients.reviewer']);

    //     if ($organization) {
    //         $query->where('publisher_org_id', $organization->id);
    //     } elseif ($filter === 'as_publisher') {
    //         $query->where('submitted_by', auth()->id());
    //     } elseif ($filter === 'as_reviewer') {
    //         $query->where('submitted_by', '!=', auth()->id());
    //         $query->whereHas('recipients', fn($q) => $q->where('reviewer_user_id', auth()->id()));
    //     }

    //     if ($status = $req->query('status')) {
    //         $query->where('status', $status);
    //     }

    //     // Simple search
    //     if ($q = $req->query('q')) {
    //         $query->where(function ($q2) use ($q) {
    //             $q2->where('subject', 'like', "%{$q}%")
    //                 ->orWhereHas('document', fn($qd) => $qd->where('title', 'like', "%{$q}%"));
    //         });
    //     }

    //     return $query->orderByDesc('updated_at')->paginate(15);
    // }

    // app/Http/Controllers/Review/ReviewRequestController.php

    public function index(Request $req, ?Organization $organization)
    {
        $filter = $req->query('filter'); // 'as_publisher' | 'as_reviewer'
        $userId = auth()->id();

        $query = ReviewRequest::query()
            ->with(['document', 'version', 'publisher', 'recipients.reviewer']);

        if ($organization) {
            // Organization-scoped queries
            if ($filter === 'as_publisher') {
                // "All Submissions" - Documents I submitted FROM this org TO other orgs
                $query->where('publisher_org_id', $organization->id)
                    ->where('submitted_by', $userId);
            } elseif ($filter === 'as_reviewer') {
                // "My Reviews" - Documents submitted TO ME from OTHER orgs
                $query->where('publisher_org_id', '!=', $organization->id)
                    ->whereHas(
                        'recipients',
                        fn($q) =>
                        $q->where('reviewer_user_id', $userId)
                            ->where('reviewer_org_id', $organization->id)
                    );
            } else {
                // Default: all reviews in this org (admin view)
                $query->where('publisher_org_id', $organization->id);
            }
        } else {
            // Global queries (no org context)
            if ($filter === 'as_publisher') {
                $query->where('submitted_by', $userId);
            } elseif ($filter === 'as_reviewer') {
                $query->whereHas(
                    'recipients',
                    fn($q) =>
                    $q->where('reviewer_user_id', $userId)
                );
            }
        }

        // Status filter
        if ($status = $req->query('status')) {
            $query->where('status', $status);
        }

        // Search
        if ($q = $req->query('q')) {
            $query->where(function ($q2) use ($q) {
                $q2->where('subject', 'like', "%{$q}%")
                    ->orWhereHas(
                        'document',
                        fn($qd) =>
                        $qd->where('title', 'like', "%{$q}%")
                    );
            });
        }

        return $query->orderByDesc('updated_at')->paginate(15);
    }


    /**
     * Show review (Global - for cross-org access)
     */
    public function showGlobal(ReviewRequest $review)
    {
        $userId = auth()->id();

        // Check if user is publisher
        $isPublisher = $review->submitted_by === $userId;

        // Check if user is a recipient
        $isReviewer = $review->recipients()
            ->where('reviewer_user_id', $userId)
            ->exists();

        if (!$isPublisher && !$isReviewer) {
            abort(403, 'You do not have access to this review');
        }

        return $review->load([
            'document',
            'version',
            'publisher',
            'recipients.reviewer',
            'comments.author',
            'attachments'
        ]);
    }

    /**
     * Create review request (organization-scoped)
     */
    // ReviewRequestController.php

    public function store(Request $req, Organization $organization)
    {
        $data = $req->validate([
            'document_id' => ['required', 'exists:documents,id'],
            'document_version_id' => ['nullable', 'exists:document_versions,id'],
            'subject' => ['required', 'string', 'max:255'],
            'body'    => ['nullable', 'string', 'max:5000'],
            'due_at'  => ['nullable', 'date'],
            'recipients' => ['required', 'array', 'min:1'],
            'recipients.*.user_id' => ['required', 'exists:users,id'],
            'recipients.*.org_id'  => ['nullable', 'exists:organizations,id'],
            'recipients.*.due_at'  => ['nullable', 'date'],
            'attachments.*' => ['file', 'max:20480']
        ]);

        $publisherId = auth()->id();
        $recipientsData = collect($data['recipients'])
            ->filter(fn($r) => (int)$r['user_id'] !== (int)$publisherId)
            ->values();

        if ($recipientsData->isEmpty()) {
            return response()->json([
                'message' => 'Cannot submit a review without valid recipients (Publisher cannot review their own document).',
                'code' => 'NO_VALID_RECIPIENTS'
            ], 422);
        }

        // Overwrite the recipients data with the filtered list
        $data['recipients'] = $recipientsData->all();

        // --- Removed redundant self-assignment check ---

        // Get document and verify it belongs to this organization
        $document = Document::with('latestVersion')->findOrFail($data['document_id']);

        if ($document->organization_id !== $organization->id) {
            return response()->json([
                'message' => 'Document does not belong to this organization'
            ], 403);
        }

        // Authorization check
        $this->authorize('submitForReview', [$document, $organization->id]);

        // Pick version
        $versionId = $data['document_version_id'] ?? $document->latest_version_id;

        $review = ReviewRequest::create([
            'document_id' => $document->id,
            'document_version_id' => $versionId,
            'publisher_org_id' => $organization->id,
            'submitted_by' => auth()->id(),
            'subject' => $data['subject'],
            'body'    => $data['body'] ?? null,
            'status'  => ReviewStatus::Sent->value,
            'due_at'  => $data['due_at'] ?? null,
        ]);

        // Create recipients
        foreach ($data['recipients'] as $r) {
            ReviewRecipient::create([
                'review_request_id' => $review->id,
                'reviewer_user_id'  => $r['user_id'],
                'reviewer_org_id'   => $r['org_id'] ?? null,
                'status'            => 'pending',
                'due_at'            => $r['due_at'] ?? null,
            ]);
        }

        // Save attachments
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

        // Log action
        ActivityLogger::log(
            $organization->id,
            'review_sent',
            subjectType: 'ReviewRequest',
            subjectId: $review->id,
            metadata: [
                'subject' => $review->subject,
                'recipients_count' => count($data['recipients'])
            ],
            description: auth()->user()->name . " sent review: {$review->subject}"
        );

        return response()->json($review->load(['recipients.reviewer', 'document', 'version']), 201);
    }

    /**
     * Show review (organization-scoped)
     */
    public function show(Organization $organization, ReviewRequest $review)
    {
        // Verify review belongs to this organization
        if ($review->publisher_org_id !== $organization->id) {
            return response()->json([
                'message' => 'Review not found in this organization'
            ], 404);
        }

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

    /**
     * Update review (organization-scoped)
     */
    public function update(Request $req, Organization $organization, ReviewRequest $review)
    {
        // Verify review belongs to this organization
        if ($review->publisher_org_id !== $organization->id) {
            return response()->json([
                'message' => 'Review not found in this organization'
            ], 404);
        }

        $this->authorize('update', $review);

        $data = $req->validate([
            'subject' => ['sometimes', 'string', 'max:255'],
            'body'    => ['sometimes', 'nullable', 'string', 'max:5000'],
            'due_at'  => ['sometimes', 'nullable', 'date'],
            'add_recipients' => ['sometimes', 'array'],
            'add_recipients.*.user_id' => ['required_with:add_recipients', 'exists:users,id'],
            'add_recipients.*.org_id'  => ['nullable', 'exists:organizations,id'],
            'add_recipients.*.due_at'  => ['nullable', 'date'],
        ]);

        // Update basic fields
        $review->fill($req->only('subject', 'body', 'due_at'))->save();

        // Add new recipients
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

            ActivityLogger::log(
                $organization->id,
                'reviewers_added',
                subjectType: 'ReviewRequest',
                subjectId: $review->id,
                metadata: ['added_count' => count($data['add_recipients'])],
                description: auth()->user()->name . " added new reviewers to: {$review->subject}"
            );
        }

        return $review->load('recipients.reviewer');
    }

    /**
     * Close review (organization-scoped)
     */
    public function close(Organization $organization, ReviewRequest $review)
    {
        // Verify review belongs to this organization
        if ($review->publisher_org_id !== $organization->id) {
            return response()->json([
                'message' => 'Review not found in this organization'
            ], 404);
        }

        $this->authorize('close', $review);

        $review->update(['status' => ReviewStatus::Closed->value]);

        ActivityLogger::log(
            $organization->id,
            'review_closed',
            subjectType: 'ReviewRequest',
            subjectId: $review->id,
            description: auth()->user()->name . " closed review: {$review->subject}"
        );

        return response()->json(['message' => 'Review closed']);
    }

    /**
     * Reopen review (organization-scoped)
     */
    public function reopen(Organization $organization, ReviewRequest $review)
    {
        // Verify review belongs to this organization
        if ($review->publisher_org_id !== $organization->id) {
            return response()->json([
                'message' => 'Review not found in this organization'
            ], 404);
        }

        $this->authorize('reopen', $review);

        $review->update(['status' => ReviewStatus::InReview->value]);

        ActivityLogger::log(
            $organization->id,
            'review_reopened',
            subjectType: 'ReviewRequest',
            subjectId: $review->id,
            description: auth()->user()->name . " reopened review: {$review->subject}"
        );

        return response()->json(['message' => 'Review reopened']);
    }

    /**
     * Attach new version (organization-scoped)
     */
    public function attachNewVersion(Request $req, Organization $organization, ReviewRequest $review)
    {
        // Verify review belongs to this organization
        if ($review->publisher_org_id !== $organization->id) {
            return response()->json([
                'message' => 'Review not found in this organization'
            ], 404);
        }

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

        ActivityLogger::log(
            $organization->id,
            'version_uploaded',
            subjectType: 'DocumentVersion',
            subjectId: $ver->id,
            metadata: ['version' => $ver->version_number, 'review_id' => $review->id],
            description: auth()->user()->name . " uploaded version {$ver->version_number} for review: {$review->subject}"
        );

        return response()->json([
            'version' => $ver,
            'version_id' => $ver->id,
        ]);
    }

    /**
     * Get activity log (organization-scoped)
     */
    public function getActivityLog(Organization $organization, ReviewRequest $review)
    {
        // Verify review belongs to this organization
        if ($review->publisher_org_id !== $organization->id) {
            return response()->json([
                'message' => 'Review not found in this organization'
            ], 404);
        }

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
     * Remove recipient (organization-scoped)
     */
    public function removeRecipient(Organization $organization, ReviewRequest $review, ReviewRecipient $recipient)
    {
        // Verify review belongs to this organization
        if ($review->publisher_org_id !== $organization->id) {
            return response()->json([
                'message' => 'Review not found in this organization'
            ], 404);
        }

        $this->authorize('update', $review);

        if ($recipient->review_request_id !== $review->id) {
            return response()->json(['message' => 'Recipient not found for this review'], 404);
        }

        if (in_array($recipient->status, ['approved', 'declined'])) {
            return response()->json([
                'message' => 'Cannot remove reviewer who has already responded'
            ], 422);
        }

        $recipientName = $recipient->reviewer->name ?? 'Unknown';
        $recipient->delete();

        ActivityLogger::log(
            $organization->id,
            'reviewer_removed',
            subjectType: 'ReviewRequest',
            subjectId: $review->id,
            metadata: ['reviewer_name' => $recipientName],
            description: auth()->user()->name . " removed reviewer {$recipientName} from: {$review->subject}"
        );

        return response()->json(['message' => 'Reviewer removed successfully']);
    }
}
