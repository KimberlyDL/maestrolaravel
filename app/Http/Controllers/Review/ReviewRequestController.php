<?php

namespace App\Http\Controllers\Review;

use App\Http\Controllers\Controller;
use App\Models\{ReviewRequest, ReviewRecipient, Document, DocumentVersion, Organization, OrganizationUser};
use Illuminate\Http\Request;
use App\Enums\ReviewStatus;
use App\Services\ActivityLogger;
use App\Services\UploadService;
use App\Services\NotificationService;

class ReviewRequestController extends Controller
{
    public function __construct(
        private readonly UploadService $uploads,
        private readonly NotificationService $notifications
    ) {}

    /**
     * Delete review
     */
    public function destroy(Organization $organization, ReviewRequest $review)
    {
        // 1. Verify organization context
        if ($review->publisher_org_id !== $organization->id) {
            return response()->json(['message' => 'Review not found in this organization'], 404);
        }

        // 2. Check Policy (This uses the new 'delete' logic in Policy)
        $this->authorize('delete', $review);

        // 3. Perform Delete
        $review->delete();

        ActivityLogger::log(
            $organization->id,
            'review_deleted',
            subjectType: 'ReviewRequest',
            subjectId: null,
            metadata: ['subject' => $review->subject],
            description: auth()->user()->name . " deleted review: {$review->subject}"
        );

        return response()->json(['message' => 'Review deleted successfully']);
    }
    /**
     * List reviews (organization-scoped)
     */
    public function index(Request $req, ?Organization $organization)
    {
        $filter = $req->query('filter'); // 'as_publisher' | 'as_reviewer' | 'pending_approval'
        $userId = auth()->id();

        $query = ReviewRequest::query()
            ->with(['document', 'version', 'publisher', 'recipients.reviewer', 'submitter', 'approver', 'rejector']);

        if ($organization) {
            // Organization-scoped queries
            if ($filter === 'as_publisher') {
                // Member view: Documents I submitted FROM this org
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
            } elseif ($filter === 'pending_approval') {
                // Admin view: All submissions needing approval
                $query->where('publisher_org_id', $organization->id)
                    ->where('approval_status', 'pending')
                    ->where('status', ReviewStatus::Sent);
            } elseif ($filter === 'all_submissions') {
                // Admin view: All submissions in this org
                $query->where('publisher_org_id', $organization->id);
            } else {
                // Default: all reviews in this org (admin view)
                $query->where('publisher_org_id', $organization->id);
            }
        } else {
            // Global queries (no org context)
            if ($filter === 'as_publisher') {
                $query->where('submitted_by', $userId);
            } elseif ($filter === 'as_reviewer') {
                $query->whereHas('recipients', fn($q) => $q->where('reviewer_user_id', $userId));
            }
        }

        // Status filter
        if ($status = $req->query('status')) {
            $query->where('status', $status);
        }

        // Approval status filter
        if ($approvalStatus = $req->query('approval_status')) {
            $query->where('approval_status', $approvalStatus);
        }

        // Search
        if ($q = $req->query('q')) {
            $query->where(function ($q2) use ($q) {
                $q2->where('subject', 'like', "%{$q}%")
                    ->orWhereHas('document', fn($qd) => $qd->where('title', 'like', "%{$q}%"));
            });
        }

        return $query->orderByDesc('updated_at')->paginate(15);
    }


    /**
     * Admin approve review submission
     */
    public function approve(Organization $organization, ReviewRequest $review, Request $req)
    {
        // Verify review belongs to this organization
        if ($review->publisher_org_id !== $organization->id) {
            return response()->json(['message' => 'Review not found in this organization'], 404);
        }

        // Check if user is admin
        if (!$organization->isUserAdmin(auth()->id())) {
            return response()->json(['message' => 'Only admins can approve reviews'], 403);
        }

        if ($review->approval_status !== 'pending') {
            return response()->json(['message' => 'Review has already been processed'], 400);
        }

        $review->approve(auth()->id());

        // Notify Submitter
        $this->notifications->send(
            $review->submitted_by,
            'Review Approved',
            "Your review '{$review->subject}' has been approved by the admin.",
            'review.approved',
            $review,
            "/orgs/{$organization->id}/reviews/{$review->id}",
            'normal',
            $organization->id
        );

        // --- NEW: Notify Recipients (Now that it is approved) ---
        $review->load('recipients');
        foreach ($review->recipients as $r) {
            $this->notifications->send(
                $r->reviewer_user_id,
                'New Review Request',
                "You have been assigned to review: {$review->subject}",
                'review.requested',
                $review,
                "/incoming-reviews/{$review->id}",
                'high',
                $organization->id
            );
        }

        ActivityLogger::log(
            $organization->id,
            'review_approved_by_admin',
            subjectType: 'ReviewRequest',
            subjectId: $review->id,
            metadata: ['submitter' => $review->submitter->name],
            description: auth()->user()->name . " approved review submission: {$review->subject}"
        );

        return response()->json([
            'message' => 'Review approved successfully',
            'review' => $review->load(['approver', 'submitter'])
        ]);
    }

    /**
     * Admin reject review submission
     */
    public function reject(Organization $organization, ReviewRequest $review, Request $req)
    {
        // Verify review belongs to this organization
        if ($review->publisher_org_id !== $organization->id) {
            return response()->json(['message' => 'Review not found in this organization'], 404);
        }

        // Check if user is admin
        if (!$organization->isUserAdmin(auth()->id())) {
            return response()->json(['message' => 'Only admins can reject reviews'], 403);
        }

        if ($review->approval_status !== 'pending') {
            return response()->json(['message' => 'Review has already been processed'], 400);
        }

        $data = $req->validate([
            'reason' => 'nullable|string|max:500'
        ]);

        $review->reject(auth()->id(), $data['reason'] ?? null);

        // Notify Submitter
        $this->notifications->send(
            $review->submitted_by,
            'Review Rejected',
            "Your review '{$review->subject}' was rejected. Reason: " . ($data['reason'] ?? 'No reason provided'),
            'review.rejected',
            $review,
            "/orgs/{$organization->id}/reviews/{$review->id}",
            'urgent',
            $organization->id
        );

        ActivityLogger::log(
            $organization->id,
            'review_rejected_by_admin',
            subjectType: 'ReviewRequest',
            subjectId: $review->id,
            metadata: ['submitter' => $review->submitter->name, 'reason' => $data['reason'] ?? null],
            description: auth()->user()->name . " rejected review submission: {$review->subject}"
        );

        return response()->json([
            'message' => 'Review rejected successfully',
            'review' => $review->load(['rejector', 'submitter'])
        ]);
    }

    /**
     * Show review (Org-scoped)
     */
    public function show(Organization $organization, ReviewRequest $review)
    {
        // Verify review belongs to org OR user is recipient from this org
        $userId = auth()->id();
        $isPublisher = $review->publisher_org_id === $organization->id;
        $isRecipient = $review->recipients()
            ->where('reviewer_user_id', $userId)
            ->where('reviewer_org_id', $organization->id)
            ->exists();

        if (!$isPublisher && !$isRecipient) {
            abort(403, 'Review not accessible in this organization');
        }

        // Use policy for final check
        $this->authorize('view', $review);

        return $review->load([
            'document',
            'version',
            'publisher',
            'recipients.reviewer',
            'recipients.org',
            'comments.author',
            'attachments'
        ]);
    }

    /**
     * Show review (Global - no org context)
     */
    public function showGlobal(ReviewRequest $review)
    {
        $this->authorize('view', $review);

        return $review->load([
            'document',
            'version',
            'publisher',
            'recipients.reviewer',
            'recipients.org',
            'comments.author',
            'attachments'
        ]);
    }


    /**
     * Store review
     */
    public function store(Request $req, Organization $organization)
    {
        $data = $req->validate([
            'document_id' => ['required', 'exists:documents,id'],
            'document_version_id' => ['nullable', 'exists:document_versions,id'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:5000'],
            'due_at' => ['nullable', 'date'],
            'recipients' => ['required', 'array', 'min:1'],
            'recipients.*.user_id' => ['required', 'exists:users,id'],
            'recipients.*.org_id' => ['nullable', 'exists:organizations,id'],
            'recipients.*.due_at' => ['nullable', 'date'],
            'attachments.*' => ['file', 'max:20480']
        ]);

        if (!$organization->hasMember(auth()->id())) {
            abort(403, 'You are not a member of this organization');
        }

        $document = Document::with('latestVersion')->findOrFail($data['document_id']);
        if ($document->organization_id !== $organization->id) {
            abort(403, 'Document does not belong to this organization');
        }

        $publisherId = auth()->id();

        // Filter out self-assignment
        $recipients = collect($data['recipients'])
            ->filter(fn($r) => (int)$r['user_id'] !== (int)$publisherId)
            ->values();

        if ($recipients->isEmpty()) {
            return response()->json([
                'message' => 'Cannot submit a review without valid recipients',
                'code' => 'NO_VALID_RECIPIENTS'
            ], 422);
        }

        $versionId = $data['document_version_id'] ?? $document->latest_version_id;

        // --- NEW LOGIC: Check for auto-approval (if admin) ---
        $isPublisherAdmin = $organization->isUserAdmin($publisherId);
        $approvalStatus = $isPublisherAdmin ? 'approved' : 'pending';
        $approvedBy = $isPublisherAdmin ? $publisherId : null;
        $approvedAt = $isPublisherAdmin ? now() : null;

        $review = ReviewRequest::create([
            'document_id' => $document->id,
            'document_version_id' => $versionId,
            'publisher_org_id' => $organization->id,
            'submitted_by' => $publisherId,
            'subject' => $data['subject'],
            'body' => $data['body'] ?? null,
            'status' => ReviewStatus::Sent->value,
            'due_at' => $data['due_at'] ?? null,
            'approval_status' => $approvalStatus,
            'approved_by' => $approvedBy,
            'approved_at' => $approvedAt,
        ]);

        foreach ($recipients as $r) {
            ReviewRecipient::create([
                'review_request_id' => $review->id,
                'reviewer_user_id' => $r['user_id'],
                'reviewer_org_id' => $r['org_id'] ?? null,
                'status' => 'pending',
                'due_at' => $r['due_at'] ?? null,
            ]);

            // --- CHANGED: Only notify recipients if AUTO-APPROVED ---
            if ($approvalStatus === 'approved') {
                $this->notifications->send(
                    $r['user_id'],
                    'New Review Request',
                    "You have been assigned to review: {$review->subject}",
                    'review.requested',
                    $review,
                    "/incoming-reviews/{$review->id}",
                    'high',
                    $organization->id
                );
            }
        }

        // --- NEW: Notify Admins if Pending ---
        if ($approvalStatus === 'pending') {
            // Find admins to notify
            $adminIds = $organization->users()
                ->wherePivot('role', 'admin')
                ->pluck('users.id');

            foreach ($adminIds as $adminId) {
                // Don't notify the submitter if they happen to be an admin (though isPublisherAdmin check handles most)
                if ($adminId === $publisherId) continue;

                $this->notifications->send(
                    $adminId,
                    'Review Approval Needed',
                    "A new review '{$review->subject}' requires your approval.",
                    'review.pending_approval',
                    $review,
                    "/orgs/{$organization->id}/reviews", // Link to admin list
                    'high',
                    $organization->id
                );
            }
        }

        if ($req->hasFile('attachments')) {
            foreach ($req->file('attachments') as $file) {
                $path = $file->store("reviews/{$review->id}/attachments", 'public');
                $review->attachments()->create([
                    'uploaded_by' => $publisherId,
                    'file_path' => $path,
                    'label' => $file->getClientOriginalName()
                ]);
            }
        }

        ActivityLogger::log(
            $organization->id,
            'review_sent',
            subjectType: 'ReviewRequest',
            subjectId: $review->id,
            metadata: ['subject' => $review->subject, 'recipients_count' => $recipients->count(), 'auto_approved' => $isPublisherAdmin],
            description: auth()->user()->name . " sent review: {$review->subject}"
        );

        return response()->json($review->load(['recipients.reviewer', 'document', 'version']), 201);
    }

    /**
     * Update review details
     */
    public function updateDetails(Organization $organization, ReviewRequest $review, Request $req)
    {
        $this->authorize('update', $review);

        $data = $req->validate([
            'subject' => 'sometimes|string|max:255',
            'body' => 'sometimes|nullable|string|max:5000',
            'due_at' => 'sometimes|nullable|date',
        ]);

        $review->update($data);

        ActivityLogger::log(
            $organization->id,
            'review_details_updated',
            subjectType: 'ReviewRequest',
            subjectId: $review->id,
            metadata: ['updated_fields' => array_keys($data)],
            description: auth()->user()->name . " updated review: {$review->subject}"
        );

        return response()->json([
            'message' => 'Review updated successfully',
            'review' => $review->fresh()
        ]);
    }

    /**
     * Close review
     */
    public function close(Organization $organization, ReviewRequest $review)
    {
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
     * Reopen review
     */
    public function reopen(Organization $organization, ReviewRequest $review)
    {
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
     * Attach new version
     */
    public function attachNewVersion(Request $req, Organization $organization, ReviewRequest $review)
    {
        $this->authorize('attachVersion', $review);

        $data = $req->validate([
            'file' => ['required', 'file', 'max:20480'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $document = $review->document()->with('versions')->first();
        $next = ($document->versions()->max('version_number') ?? 0) + 1;

        $stored = $this->uploads->storeDocumentVersion($document->id, $data['file']);

        $ver = $document->versions()->create([
            'version_number' => $next,
            'file_path' => $stored['path'],
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
            description: auth()->user()->name . " uploaded version {$ver->version_number}"
        );

        return response()->json([
            'version' => $ver,
            'version_id' => $ver->id,
        ]);
    }

    /**
     * Get activity log
     */
    public function getActivityLog(Organization $organization, ReviewRequest $review)
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
     * Update review (add recipients)
     */
    public function update(Request $req, Organization $organization, ReviewRequest $review)
    {
        if ($review->publisher_org_id !== $organization->id) {
            return response()->json(['message' => 'Review not found in this organization'], 404);
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

        $review->fill($req->only('subject', 'body', 'due_at'))->save();

        if (!empty($data['add_recipients'])) {
            foreach ($data['add_recipients'] as $r) {
                $recipient = ReviewRecipient::firstOrCreate([
                    'review_request_id' => $review->id,
                    'reviewer_user_id'  => $r['user_id'],
                ], [
                    'reviewer_org_id'   => $r['org_id'] ?? null,
                    'status'            => 'pending',
                    'due_at'            => $r['due_at'] ?? null,
                ]);

                // --- NEW: Send notification immediately if review is already approved ---
                if ($review->approval_status === 'approved') {
                    $this->notifications->send(
                        $r['user_id'],
                        'New Review Request',
                        "You have been assigned to review: {$review->subject}",
                        'review.requested',
                        $review,
                        "/incoming-reviews/{$review->id}",
                        'high',
                        $organization->id
                    );
                }
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
     * Update recipient due date
     */
    public function updateRecipientDue(Organization $organization, ReviewRequest $review, ReviewRecipient $recipient, Request $req)
    {
        if ($review->publisher_org_id !== $organization->id) {
            return response()->json(['message' => 'Review not found in this organization'], 404);
        }

        if ($review->submitted_by !== auth()->id() && !$organization->isUserAdmin(auth()->id())) {
            return response()->json(['message' => 'You do not have permission to update recipients'], 403);
        }

        if ($recipient->review_request_id !== $review->id) {
            return response()->json(['message' => 'Recipient not found for this review'], 404);
        }

        $data = $req->validate(['due_at' => 'nullable|date']);
        $recipient->update(['due_at' => $data['due_at']]);

        ActivityLogger::log(
            $organization->id,
            'recipient_due_date_updated',
            subjectType: 'ReviewRecipient',
            subjectId: $recipient->id,
            metadata: ['reviewer' => $recipient->reviewer->name, 'new_due_at' => $data['due_at']],
            description: auth()->user()->name . " updated due date for {$recipient->reviewer->name}"
        );

        return response()->json(['message' => 'Due date updated successfully', 'recipient' => $recipient->fresh()]);
    }


    /**
     * Remind reviewer
     */
    public function remindReviewer(Organization $organization, ReviewRequest $review, ReviewRecipient $recipient)
    {
        if ($review->publisher_org_id !== $organization->id) {
            return response()->json(['message' => 'Review not found in this organization'], 404);
        }

        if ($review->submitted_by !== auth()->id() && !$organization->isUserAdmin(auth()->id())) {
            return response()->json(['message' => 'You do not have permission to send reminders'], 403);
        }

        if ($recipient->review_request_id !== $review->id) {
            return response()->json(['message' => 'Recipient not found for this review'], 404);
        }

        if (in_array($recipient->status, ['approved', 'declined'])) {
            return response()->json(['message' => 'Reviewer has already responded'], 400);
        }

        ActivityLogger::log(
            $organization->id,
            'reviewer_reminded',
            subjectType: 'ReviewRecipient',
            subjectId: $recipient->id,
            metadata: ['reviewer' => $recipient->reviewer->name],
            description: auth()->user()->name . " sent a reminder to {$recipient->reviewer->name}"
        );


        $this->notifications->send(
            $recipient->reviewer_user_id,
            'Review Reminder',
            "Reminder: You have a pending review for '{$review->subject}'",
            'review.reminder',
            $review,
            "/incoming-reviews/{$review->id}",
            'high',
            $organization->id
        );
        return response()->json(['message' => 'Reminder sent successfully']);
    }

    /**
     * Remove recipient
     */
    public function removeRecipient(Organization $organization, ReviewRequest $review, ReviewRecipient $recipient)
    {
        if ($review->publisher_org_id !== $organization->id) {
            return response()->json(['message' => 'Review not found in this organization'], 404);
        }

        $this->authorize('update', $review);

        if ($recipient->review_request_id !== $review->id) {
            return response()->json(['message' => 'Recipient not found for this review'], 404);
        }

        if (in_array($recipient->status, ['approved', 'declined'])) {
            return response()->json(['message' => 'Cannot remove reviewer who has already responded'], 422);
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


    #region REVIEWER

    /**
     * Incoming reviews (Reviewer POV)
     */
    public function indexIncoming(Request $req, Organization $organization)
    {
        $userId = auth()->id();
        $status = $req->query('status');
        $q = $req->query('q');

        $query = ReviewRequest::query()
            ->whereHas('recipients', function ($query) use ($userId, $organization) {
                $query->where('reviewer_user_id', $userId)
                    ->where('reviewer_org_id', $organization->id);
            })
            // --- NEW: Only show APPROVED reviews to recipients ---
            ->where('approval_status', 'approved')
            ->with(['publisher', 'document', 'submitter', 'recipients' => function ($query) use ($userId) {
                $query->where('reviewer_user_id', $userId);
            }]);

        if ($status === 'pending') {
            $query->whereHas('recipients', function ($q) use ($userId) {
                $q->where('reviewer_user_id', $userId)->where('status', 'pending');
            });
        } elseif ($status === 'history') {
            $query->whereHas('recipients', function ($q) use ($userId) {
                $q->where('reviewer_user_id', $userId)
                    ->whereIn('status', ['approved', 'declined', 'viewed']);
            });
        }

        if ($q) {
            $query->where(function ($sub) use ($q) {
                $sub->where('subject', 'like', "%{$q}%")
                    ->orWhereHas('publisher', fn($p) => $p->where('name', 'like', "%{$q}%"));
            });
        }

        return $query->orderByDesc('created_at')->paginate(15);
    }

    /**
     * Show incoming review
     */
    public function showIncoming(Organization $organization, ReviewRequest $review)
    {
        $userId = auth()->id();

        // --- NEW: Block access if not approved ---
        if ($review->approval_status !== 'approved') {
            abort(403, 'Review is pending approval');
        }

        $isRecipient = $review->recipients()
            ->where('reviewer_user_id', $userId)
            ->where('reviewer_org_id', $organization->id)
            ->exists();

        if (!$isRecipient) {
            abort(403, 'You are not a recipient of this review');
        }

        return $review->load([
            'document',
            'version',
            'publisher',
            'submitter',
            'recipients' => function ($q) use ($userId) {
                $q->where('reviewer_user_id', $userId);
            },
            'attachments'
        ]);
    }
    #endregion
}
