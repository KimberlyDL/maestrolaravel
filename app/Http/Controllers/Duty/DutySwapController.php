<?php

namespace App\Http\Controllers\Duty;

use App\Http\Controllers\Controller;
use App\Models\DutySwapRequest;
use App\Models\DutyAssignment;
use App\Models\Organization;
use App\Services\NotificationService;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

class DutySwapController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService
    ) {}

    public function index(Request $request, Organization $organization)
    {
        $query = DutySwapRequest::whereHas('dutyAssignment.dutySchedule', function ($q) use ($organization) {
            $q->where('organization_id', $organization->id);
        })->with([
            'dutyAssignment.dutySchedule',
            'fromOfficer:id,name,email,avatar,avatar_url',
            'toOfficer:id,name,email,avatar,avatar_url',
            'reviewer:id,name',
        ]);

        if ($request->boolean('member_view')) {
            $userId = auth()->id();
            $query->where(function ($q) use ($userId) {
                $q->where('from_officer_id', $userId)
                    ->orWhere('to_officer_id', $userId)
                    ->orWhere(function ($subQ) use ($userId) {
                        $subQ->whereNull('to_officer_id')
                            ->where('status', 'pending')
                            ->where('from_officer_id', '!=', $userId);
                    });
            });
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereHas('dutyAssignment.dutySchedule', function ($q) use ($request) {
                $q->whereBetween('date', [$request->start_date, $request->end_date]);
            });
        }

        $swapRequests = $query->latest()->get();

        return response()->json($swapRequests);
    }

    public function store(Request $request, Organization $organization, DutyAssignment $dutyAssignment)
    {
        if ($dutyAssignment->officer_id !== auth()->id()) {
            return response()->json(['message' => 'You can only request swaps for your own assignments'], 403);
        }

        if ($dutyAssignment->status !== 'confirmed') {
            return response()->json(['message' => 'You can only swap confirmed assignments'], 400);
        }

        $dutyAssignment->load('dutySchedule');

        $dutyDate = $dutyAssignment->dutySchedule->date;
        if ($dutyDate < now()->toDateString()) {
            return response()->json(['message' => 'Cannot swap past duties'], 400);
        }

        $existingSwap = DutySwapRequest::where('duty_assignment_id', $dutyAssignment->id)
            ->whereIn('status', ['pending', 'approved'])
            ->exists();

        if ($existingSwap) {
            return response()->json(['message' => 'There is already a pending swap request for this assignment'], 400);
        }

        $data = $request->validate([
            'to_officer_id' => 'nullable|exists:users,id',
            'reason' => 'required|string|max:1000',
        ]);

        if (!empty($data['to_officer_id'])) {
            if ($data['to_officer_id'] == auth()->id()) {
                return response()->json(['message' => 'You cannot swap with yourself'], 400);
            }
            if (!$organization->hasMember($data['to_officer_id'])) {
                return response()->json(['message' => 'Target officer is not a member of this organization'], 400);
            }
        }

        $swapRequest = DutySwapRequest::create([
            'duty_assignment_id' => $dutyAssignment->id,
            'from_officer_id' => auth()->id(),
            'to_officer_id' => $data['to_officer_id'] ?? null,
            'reason' => $data['reason'],
            'status' => 'pending',
        ]);

        if ($swapRequest->to_officer_id) {
            $this->notificationService->send(
                $swapRequest->to_officer_id,
                'Duty Swap Request',
                auth()->user()->name . " wants to swap '{$dutyAssignment->dutySchedule->title}' with you.",
                'duty.swap_requested',
                $swapRequest,
                "/org/{$organization->id}/duty/swaps",
                'high',
                $organization->id
            );
        }

        ActivityLogger::log(
            $organization->id,
            'duty.swap.requested',
            DutySwapRequest::class,
            $swapRequest->id,
            [
                'duty_schedule_id' => $dutyAssignment->duty_schedule_id,
                'duty_title' => $dutyAssignment->dutySchedule->title,
                'from_officer' => auth()->user()->name,
                'to_officer' => $data['to_officer_id'] ? \App\Models\User::find($data['to_officer_id'])->name : 'Anyone',
                'duty_date' => $dutyAssignment->dutySchedule->date,
            ],
            auth()->user()->name . ' requested to swap duty: ' . $dutyAssignment->dutySchedule->title
        );

        return response()->json($swapRequest->load([
            'fromOfficer',
            'toOfficer',
            'dutyAssignment.dutySchedule'
        ]), 201);
    }

    public function accept(Request $request, Organization $organization, DutySwapRequest $swapRequest)
    {
        $swapRequest->load(['dutyAssignment.dutySchedule', 'fromOfficer']);

        $userId = auth()->id();

        if ($swapRequest->from_officer_id === $userId) {
            return response()->json(['message' => 'You cannot accept your own swap request'], 400);
        }

        if ($swapRequest->to_officer_id && $swapRequest->to_officer_id !== $userId) {
            return response()->json(['message' => 'This swap is directed to another officer'], 403);
        }

        if ($swapRequest->status !== 'pending') {
            return response()->json(['message' => 'This swap is no longer available'], 400);
        }

        $data = $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        $swapRequest->update([
            'status' => 'accepted',
            'to_officer_id' => $userId,
            'reviewed_by' => $userId,
            'reviewed_at' => now(),
            'review_notes' => $data['notes'] ?? 'Accepted by member'
        ]);

        $assignment = $swapRequest->dutyAssignment;
        $assignment->update([
            'officer_id' => $userId,
            'status' => 'confirmed',
            'assigned_by' => $userId,
            'notes' => ($assignment->notes ?? '') . "\n[Swapped from " . $swapRequest->fromOfficer->name . "]",
        ]);

        $this->notificationService->send(
            $swapRequest->from_officer_id,
            'Swap Accepted',
            auth()->user()->name . " accepted your swap request for '{$assignment->dutySchedule->title}'.",
            'duty.swap_accepted',
            $swapRequest,
            "/org/{$organization->id}/duty/assignments",
            'normal',
            $organization->id
        );

        ActivityLogger::log(
            $organization->id,
            'duty.swap.accepted',
            DutySwapRequest::class,
            $swapRequest->id,
            [
                'duty_schedule_id' => $assignment->duty_schedule_id,
                'duty_title' => $assignment->dutySchedule->title,
                'from_officer' => $swapRequest->fromOfficer->name,
                'accepted_by' => auth()->user()->name,
                'duty_date' => $assignment->dutySchedule->date,
            ],
            auth()->user()->name . ' accepted swap from ' . $swapRequest->fromOfficer->name . ' for duty: ' . $assignment->dutySchedule->title
        );

        return response()->json([
            'message' => 'Swap accepted successfully',
            'swap' => $swapRequest->fresh(['reviewer', 'dutyAssignment', 'fromOfficer', 'toOfficer']),
            'assignment' => $assignment->fresh(['officer', 'dutySchedule'])
        ]);
    }

    public function decline(Request $request, Organization $organization, DutySwapRequest $swapRequest)
    {
        $swapRequest->load(['dutyAssignment.dutySchedule']);
        $userId = auth()->id();

        if ($swapRequest->to_officer_id && $swapRequest->to_officer_id !== $userId) {
            return response()->json(['message' => 'You cannot decline this swap request'], 403);
        }

        if ($swapRequest->from_officer_id === $userId) {
            return response()->json(['message' => 'Use cancel endpoint to cancel your own swap request'], 400);
        }

        if ($swapRequest->status !== 'pending') {
            return response()->json(['message' => 'This swap is no longer available'], 400);
        }

        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        $swapRequest->update([
            'status' => 'declined',
            'reviewed_by' => $userId,
            'reviewed_at' => now(),
            'review_notes' => $data['reason'] ?? 'Declined by member'
        ]);

        $this->notificationService->send(
            $swapRequest->from_officer_id,
            'Swap Declined',
            auth()->user()->name . " declined your swap request.",
            'duty.swap_declined',
            $swapRequest,
            "/org/{$organization->id}/duty/swaps",
            'normal',
            $organization->id
        );

        ActivityLogger::log(
            $organization->id,
            'duty.swap.declined_by_member',
            DutySwapRequest::class,
            $swapRequest->id,
            [
                'declined_by' => auth()->user()->name,
                'reason' => $data['reason'] ?? null,
                'duty_title' => $swapRequest->dutyAssignment->dutySchedule->title,
            ],
            auth()->user()->name . ' declined swap request'
        );

        return response()->json([
            'message' => 'Swap request declined',
            'swap' => $swapRequest->fresh(['reviewer'])
        ]);
    }

    /**
     * NEW: Admin review with reassignment capability
     */
    public function adminReview(Request $request, Organization $organization, DutySwapRequest $swapRequest)
    {
        $swapRequest->load(['dutyAssignment.dutySchedule', 'fromOfficer', 'toOfficer']);

        $data = $request->validate([
            'action' => 'required|in:approve,reject',
            'review_notes' => 'nullable|string|max:1000',
            'reassign_to' => 'nullable|exists:users,id', // For admin approval with reassignment
        ]);

        if ($swapRequest->status !== 'pending') {
            return response()->json(['message' => 'Can only review pending swap requests'], 400);
        }

        $assignment = $swapRequest->dutyAssignment;

        if ($data['action'] === 'reject') {
            $swapRequest->update([
                'status' => 'rejected',
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
                'review_notes' => $data['review_notes'] ?? 'Rejected by admin - officer must attend',
            ]);

            $this->notificationService->send(
                $swapRequest->from_officer_id,
                'Swap Rejected by Admin',
                "Your swap request for '{$assignment->dutySchedule->title}' was rejected. You must attend this duty.",
                'duty.swap_rejected',
                $swapRequest,
                "/org/{$organization->id}/duty/assignments",
                'urgent',
                $organization->id
            );

            ActivityLogger::log(
                $organization->id,
                'duty.swap.admin_rejected',
                DutySwapRequest::class,
                $swapRequest->id,
                [
                    'action' => 'reject',
                    'reviewed_by' => auth()->user()->name,
                    'duty_title' => $assignment->dutySchedule->title,
                ],
                'Admin rejected swap request - officer must attend'
            );

            return response()->json([
                'message' => 'Swap request rejected. Officer must attend the duty.',
                'swap' => $swapRequest->fresh(['reviewer', 'dutyAssignment', 'fromOfficer', 'toOfficer'])
            ]);
        }

        // APPROVE ACTION - Admin must reassign
        if (empty($data['reassign_to'])) {
            return response()->json([
                'message' => 'Admin must reassign the duty to another officer when approving a swap'
            ], 400);
        }

        if ($data['reassign_to'] == $swapRequest->from_officer_id) {
            return response()->json(['message' => 'Cannot reassign to the same officer'], 400);
        }

        if (!$organization->hasMember($data['reassign_to'])) {
            return response()->json(['message' => 'Reassigned officer is not a member'], 400);
        }

        $swapRequest->update([
            'status' => 'approved',
            'to_officer_id' => $data['reassign_to'],
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'review_notes' => $data['review_notes'] ?? 'Approved by admin - duty reassigned',
        ]);

        $assignment->update([
            'officer_id' => $data['reassign_to'],
            'status' => 'assigned', // Reset to assigned for new officer
            'assigned_by' => auth()->id(),
            'notes' => ($assignment->notes ?? '') . "\n[Admin reassigned from " . $swapRequest->fromOfficer->name . "]",
        ]);

        // Notify original officer
        $this->notificationService->send(
            $swapRequest->from_officer_id,
            'Swap Approved',
            "Your swap request for '{$assignment->dutySchedule->title}' was approved by admin.",
            'duty.swap_approved',
            $swapRequest,
            "/org/{$organization->id}/duty/assignments",
            'normal',
            $organization->id
        );

        // Notify new officer
        $newOfficer = \App\Models\User::find($data['reassign_to']);
        $this->notificationService->send(
            $data['reassign_to'],
            'New Duty Assignment',
            "You have been assigned to '{$assignment->dutySchedule->title}' by admin.",
            'duty.assigned',
            $assignment->dutySchedule,
            "/org/{$organization->id}/duty/assignments",
            'high',
            $organization->id
        );

        ActivityLogger::log(
            $organization->id,
            'duty.swap.admin_approved_reassigned',
            DutySwapRequest::class,
            $swapRequest->id,
            [
                'action' => 'approve',
                'reviewed_by' => auth()->user()->name,
                'reassigned_to' => $newOfficer->name,
                'duty_title' => $assignment->dutySchedule->title,
            ],
            'Admin approved swap and reassigned duty'
        );

        return response()->json([
            'message' => 'Swap request approved and duty reassigned successfully',
            'swap' => $swapRequest->fresh(['reviewer', 'dutyAssignment', 'fromOfficer', 'toOfficer']),
            'assignment' => $assignment->fresh(['officer', 'dutySchedule'])
        ]);
    }

    public function cancel(Organization $organization, DutySwapRequest $swapRequest)
    {
        $swapRequest->load(['dutyAssignment.dutySchedule']);

        if ($swapRequest->from_officer_id !== auth()->id()) {
            return response()->json(['message' => 'You can only cancel your own swap requests'], 403);
        }

        if (!in_array($swapRequest->status, ['pending'])) {
            return response()->json(['message' => 'Can only cancel pending requests'], 400);
        }

        $swapRequest->update(['status' => 'cancelled']);

        ActivityLogger::log(
            $organization->id,
            'duty.swap.cancelled',
            DutySwapRequest::class,
            $swapRequest->id,
            [
                'cancelled_by' => auth()->user()->name,
                'duty_title' => $swapRequest->dutyAssignment->dutySchedule->title,
            ],
            auth()->user()->name . ' cancelled swap request'
        );

        return response()->json([
            'message' => 'Swap request cancelled',
            'swap' => $swapRequest->fresh()
        ]);
    }
}
