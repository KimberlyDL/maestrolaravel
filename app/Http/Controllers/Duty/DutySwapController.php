<?php

namespace App\Http\Controllers\Duty;

use App\Http\Controllers\Controller;
use App\Models\DutySwapRequest;
use App\Models\DutyAssignment;
use App\Models\Organization;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

class DutySwapController extends Controller
{
    /**
     * List swap requests
     * ?member_view=true - shows swaps relevant to logged-in user
     * ?status=pending - filter by status
     */
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

        // Member view - show swaps relevant to the user
        if ($request->boolean('member_view')) {
            $userId = auth()->id();
            $query->where(function ($q) use ($userId) {
                // My swap requests
                $q->where('from_officer_id', $userId)
                    // OR swaps directed to me
                    ->orWhere('to_officer_id', $userId)
                    // OR open swaps (available to anyone) that are pending
                    ->orWhere(function ($subQ) use ($userId) {
                        $subQ->whereNull('to_officer_id')
                            ->where('status', 'pending')
                            ->where('from_officer_id', '!=', $userId);
                    });
            });
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by date range
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereHas('dutyAssignment.dutySchedule', function ($q) use ($request) {
                $q->whereBetween('date', [$request->start_date, $request->end_date]);
            });
        }

        $swapRequests = $query->latest()->get();

        return response()->json($swapRequests);
    }

    /**
     * Create swap request (by member)
     */
    public function store(Request $request, Organization $organization, DutyAssignment $dutyAssignment)
    {
        // Verify ownership
        if ($dutyAssignment->officer_id !== auth()->id()) {
            return response()->json([
                'message' => 'You can only request swaps for your own assignments'
            ], 403);
        }

        // Check if assignment is confirmed
        if ($dutyAssignment->status !== 'confirmed') {
            return response()->json([
                'message' => 'You can only swap confirmed assignments'
            ], 400);
        }

        // Load the duty schedule relationship
        $dutyAssignment->load('dutySchedule');

        // Check if duty is in the future
        $dutyDate = $dutyAssignment->dutySchedule->date;
        if ($dutyDate < now()->toDateString()) {
            return response()->json([
                'message' => 'Cannot swap past duties'
            ], 400);
        }

        // Check for existing pending swap
        $existingSwap = DutySwapRequest::where('duty_assignment_id', $dutyAssignment->id)
            ->whereIn('status', ['pending', 'approved'])
            ->exists();

        if ($existingSwap) {
            return response()->json([
                'message' => 'There is already a pending swap request for this assignment'
            ], 400);
        }

        $data = $request->validate([
            'to_officer_id' => 'nullable|exists:users,id',
            'reason' => 'required|string|max:1000',
        ]);

        // If to_officer specified, verify they're a member and not the same person
        if (!empty($data['to_officer_id'])) {
            if ($data['to_officer_id'] == auth()->id()) {
                return response()->json([
                    'message' => 'You cannot swap with yourself'
                ], 400);
            }

            $isMember = $organization->hasMember($data['to_officer_id']);
            if (!$isMember) {
                return response()->json([
                    'message' => 'Target officer is not a member of this organization'
                ], 400);
            }
        }

        $swapRequest = DutySwapRequest::create([
            'duty_assignment_id' => $dutyAssignment->id,
            'from_officer_id' => auth()->id(),
            'to_officer_id' => $data['to_officer_id'] ?? null,
            'reason' => $data['reason'],
            'status' => 'pending',
        ]);

        // Log activity
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

    /**
     * Member accepts a swap request (takes over the duty)
     */
    public function accept(Request $request, Organization $organization, DutySwapRequest $swapRequest)
    {
        // Load relationships
        $swapRequest->load(['dutyAssignment.dutySchedule', 'fromOfficer']);

        $userId = auth()->id();

        // Verify the swap is available to this user
        if ($swapRequest->from_officer_id === $userId) {
            return response()->json([
                'message' => 'You cannot accept your own swap request'
            ], 400);
        }

        // Check if targeted to specific officer
        if ($swapRequest->to_officer_id && $swapRequest->to_officer_id !== $userId) {
            return response()->json([
                'message' => 'This swap is directed to another officer'
            ], 403);
        }

        if ($swapRequest->status !== 'pending') {
            return response()->json([
                'message' => 'This swap is no longer available'
            ], 400);
        }

        $data = $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        // Update swap status
        $swapRequest->update([
            'status' => 'accepted',
            'to_officer_id' => $userId, // Set accepting officer
            'reviewed_by' => $userId,
            'reviewed_at' => now(),
            'review_notes' => $data['notes'] ?? 'Accepted by member'
        ]);

        // Reassign the duty to the accepting officer
        $assignment = $swapRequest->dutyAssignment;

        $assignment->update([
            'officer_id' => $userId,
            'status' => 'confirmed',
            'assigned_by' => $userId,
            'notes' => ($assignment->notes ?? '') . "\n[Swapped from " . $swapRequest->fromOfficer->name . "]",
        ]);

        // Log activity
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

    /**
     * Member declines a swap request
     */
    public function decline(Request $request, Organization $organization, DutySwapRequest $swapRequest)
    {
        $swapRequest->load(['dutyAssignment.dutySchedule']);

        $userId = auth()->id();

        // Check if the swap is directed to this user or is open
        if ($swapRequest->to_officer_id && $swapRequest->to_officer_id !== $userId) {
            return response()->json([
                'message' => 'You cannot decline this swap request'
            ], 403);
        }

        if ($swapRequest->from_officer_id === $userId) {
            return response()->json([
                'message' => 'Use cancel endpoint to cancel your own swap request'
            ], 400);
        }

        if ($swapRequest->status !== 'pending') {
            return response()->json([
                'message' => 'This swap is no longer available'
            ], 400);
        }

        $data = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $swapRequest->update([
            'status' => 'declined',
            'reviewed_by' => $userId,
            'reviewed_at' => now(),
            'review_notes' => $data['reason'] ?? 'Declined by member'
        ]);

        // Log activity
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
     * Admin reviews swap request (approve/reject)
     * 
     * FIX: Admin approval now works correctly:
     * - If to_officer_id is specified (directed swap), duty is reassigned immediately
     * - If to_officer_id is NULL (open swap), request stays pending for members to accept
     * - Rejected swaps are closed with admin notes
     */
    public function review(Request $request, Organization $organization, DutySwapRequest $swapRequest)
    {
        $this->authorize('manageDutySchedules', $organization);

        $swapRequest->load(['dutyAssignment.dutySchedule', 'fromOfficer', 'toOfficer']);

        $data = $request->validate([
            'action' => 'required|in:approve,reject',
            'review_notes' => 'nullable|string|max:1000',
        ]);

        if ($swapRequest->status !== 'pending') {
            return response()->json([
                'message' => 'Can only review pending swap requests'
            ], 400);
        }

        $assignment = $swapRequest->dutyAssignment;

        if ($data['action'] === 'reject') {
            // Reject: Close the swap request
            $swapRequest->update([
                'status' => 'rejected',
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
                'review_notes' => $data['review_notes'] ?? 'Rejected by admin',
            ]);

            // Log activity
            ActivityLogger::log(
                $organization->id,
                'duty.swap.admin_rejected',
                DutySwapRequest::class,
                $swapRequest->id,
                [
                    'action' => 'reject',
                    'reviewed_by' => auth()->user()->name,
                    'duty_title' => $assignment->dutySchedule->title,
                    'from_officer' => $swapRequest->fromOfficer->name,
                    'to_officer' => $swapRequest->toOfficer?->name ?? 'Anyone',
                ],
                'Admin rejected swap request from ' . $swapRequest->fromOfficer->name
            );

            return response()->json([
                'message' => 'Swap request rejected successfully',
                'swap' => $swapRequest->fresh(['reviewer', 'dutyAssignment', 'fromOfficer', 'toOfficer'])
            ]);
        }

        // Approve action
        if ($swapRequest->to_officer_id) {
            // DIRECTED SWAP: Immediately reassign duty to specified officer
            $swapRequest->update([
                'status' => 'approved',
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
                'review_notes' => $data['review_notes'] ?? 'Approved by admin - duty reassigned',
            ]);

            $assignment->update([
                'officer_id' => $swapRequest->to_officer_id,
                'status' => 'confirmed',
                'assigned_by' => auth()->id(),
                'notes' => ($assignment->notes ?? '') . "\n[Swapped from " . $swapRequest->fromOfficer->name . " - Admin approved]",
            ]);

            // Log activity
            ActivityLogger::log(
                $organization->id,
                'duty.swap.admin_approved_and_assigned',
                DutySwapRequest::class,
                $swapRequest->id,
                [
                    'action' => 'approve',
                    'reviewed_by' => auth()->user()->name,
                    'duty_title' => $assignment->dutySchedule->title,
                    'from_officer' => $swapRequest->fromOfficer->name,
                    'to_officer' => $swapRequest->toOfficer->name,
                    'reassigned' => true,
                ],
                'Admin approved and reassigned swap from ' . $swapRequest->fromOfficer->name . ' to ' . $swapRequest->toOfficer->name
            );

            return response()->json([
                'message' => 'Swap request approved and duty reassigned successfully',
                'swap' => $swapRequest->fresh(['reviewer', 'dutyAssignment', 'fromOfficer', 'toOfficer']),
                'assignment' => $assignment->fresh(['officer', 'dutySchedule'])
            ]);
        } else {
            // OPEN SWAP: Keep status as pending so members can accept it
            // Admin approval just means "this is a valid swap request"
            $swapRequest->update([
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
                'review_notes' => $data['review_notes'] ?? 'Approved by admin - open for members to accept',
                // Status stays 'pending' so members can still accept
            ]);

            // Log activity
            ActivityLogger::log(
                $organization->id,
                'duty.swap.admin_approved_open',
                DutySwapRequest::class,
                $swapRequest->id,
                [
                    'action' => 'approve',
                    'reviewed_by' => auth()->user()->name,
                    'duty_title' => $assignment->dutySchedule->title,
                    'from_officer' => $swapRequest->fromOfficer->name,
                    'open_swap' => true,
                ],
                'Admin approved open swap request from ' . $swapRequest->fromOfficer->name
            );

            return response()->json([
                'message' => 'Swap request approved. Members can now accept it.',
                'swap' => $swapRequest->fresh(['reviewer', 'dutyAssignment', 'fromOfficer', 'toOfficer'])
            ]);
        }
    }

    /**
     * Cancel swap request (by requester only)
     */
    public function cancel(Organization $organization, DutySwapRequest $swapRequest)
    {
        $swapRequest->load(['dutyAssignment.dutySchedule']);

        if ($swapRequest->from_officer_id !== auth()->id()) {
            return response()->json([
                'message' => 'You can only cancel your own swap requests'
            ], 403);
        }

        if (!in_array($swapRequest->status, ['pending'])) {
            return response()->json([
                'message' => 'Can only cancel pending requests'
            ], 400);
        }

        $swapRequest->update(['status' => 'cancelled']);

        // Log activity
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
