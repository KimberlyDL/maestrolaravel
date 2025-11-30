<?php

namespace App\Http\Controllers\Duty;

use App\Http\Controllers\Controller;
use App\Models\DutySchedule;
use App\Models\DutyAssignment;
use App\Models\Organization;
use App\Services\DutyScheduleService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Carbon\Carbon;

class DutyAssignmentController extends Controller
{
    public function __construct(
        private readonly DutyScheduleService $dutyService,
        private readonly NotificationService $notificationService
    ) {}

    public function myAssignments(Request $request, Organization $organization)
    {
        $query = DutyAssignment::whereHas('dutySchedule', function ($q) use ($organization) {
            $q->where('organization_id', $organization->id);
        })
            ->where('officer_id', auth()->id())
            ->with([
                'dutySchedule' => function ($q) {
                    $q->select('id', 'title', 'description', 'date', 'start_time', 'end_time', 'location', 'required_officers', 'status', 'check_in_window_start', 'check_in_window_end', 'check_out_window_start', 'check_out_window_end');
                },
                'assigner:id,name'
            ]);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by date range
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereHas('dutySchedule', function ($q) use ($request) {
                $q->whereBetween('date', [$request->start_date, $request->end_date]);
            });
        }

        $assignments = $query->orderBy('created_at', 'desc')->get();

        return response()->json($assignments);
    }

    /**
     * Assign officer to duty - AUTO ACCEPTS ASSIGNMENT
     */
    public function store(Request $request, Organization $organization, DutySchedule $dutySchedule)
    {
        $this->authorize('manageDutySchedules', $organization);

        $data = $request->validate([
            'officer_ids' => 'required|array|min:1',
            'officer_ids.*' => 'exists:users,id',
            'notes' => 'nullable|string',
        ]);

        $assignments = $this->dutyService->assignOfficers(
            $dutySchedule,
            $data['officer_ids'],
            auth()->id(),
            $data['notes'] ?? null
        );

        // === AUTO-CONFIRM LOGIC ===
        // Automatically set status to 'confirmed' so they can Swap immediately
        foreach ($assignments as $assignment) {
            $assignment->update([
                'status' => 'confirmed',
                'confirmed_at' => now()
            ]);

            $this->notificationService->send(
                $assignment->officer_id,
                'New Duty Assignment',
                "You have been assigned to '{$dutySchedule->title}' on {$dutySchedule->date}",
                'duty.assigned',
                $dutySchedule,
                "/orgs/{$organization->id}/duties/{$dutySchedule->id}",
                'high',
                $organization->id
            );
        }

        return response()->json($assignments, 201);
    }

    /**
     * Update assignment
     */
    public function update(Request $request, Organization $organization, DutySchedule $dutySchedule, DutyAssignment $dutyAssignment)
    {
        $this->authorize('manageDutySchedules', $organization);

        $data = $request->validate([
            'status' => 'sometimes|in:assigned,confirmed,declined,completed,no_show',
            'notes' => 'nullable|string',
        ]);

        $dutyAssignment->update($data);

        if (isset($data['status']) && $data['status'] === 'confirmed') {
            $dutyAssignment->update(['confirmed_at' => now()]);
        }

        return response()->json($dutyAssignment->load('officer'));
    }

    /**
     * Remove assignment
     */
    public function destroy(Organization $organization, DutySchedule $dutySchedule, DutyAssignment $dutyAssignment)
    {
        $this->authorize('manageDutySchedules', $organization);

        // Optional: Notify officer that assignment was removed? 
        // For now, adhering to existing logic (just delete).
        $dutyAssignment->delete();

        return response()->json(['message' => 'Assignment removed']);
    }

    /**
     * Officer confirms or declines duty
     */
    public function respond(Request $request, Organization $organization, DutySchedule $dutySchedule, DutyAssignment $dutyAssignment)
    {
        if ($dutyAssignment->officer_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'response' => 'required|in:confirm,decline',
            'notes' => 'nullable|string',
        ]);

        $status = $data['response'] === 'confirm' ? 'confirmed' : 'declined';
        $dutyAssignment->update([
            'status' => $status,
            'notes' => $data['notes'] ?? $dutyAssignment->notes,
            'confirmed_at' => $data['response'] === 'confirm' ? now() : null,
        ]);

        // Notify Assigner (or Admin) about response
        // Target: The person who assigned the duty, or the schedule creator
        $recipientId = $dutyAssignment->assigned_by ?? $dutySchedule->created_by;

        if ($recipientId) {
            $message = $status === 'confirmed'
                ? auth()->user()->name . " confirmed attendance for '{$dutySchedule->title}'."
                : auth()->user()->name . " declined '{$dutySchedule->title}'.";

            $this->notificationService->send(
                $recipientId,
                'Duty Response: ' . ucfirst($status),
                $message,
                $status === 'confirmed' ? 'duty.confirmed' : 'duty.declined',
                $dutySchedule,
                "/orgs/{$organization->id}/duties/{$dutySchedule->id}",
                'normal',
                $organization->id
            );
        }

        return response()->json($dutyAssignment->fresh(['dutySchedule', 'officer']));
    }

    /**
     * CHECK IN - USER FRIENDLY
     */
    public function checkIn(Organization $organization, DutySchedule $dutySchedule, DutyAssignment $dutyAssignment)
    {
        if ($dutyAssignment->officer_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($dutyAssignment->status !== 'confirmed') {
            return response()->json(['message' => 'You must confirm this assignment before checking in.'], 400);
        }

        if ($dutyAssignment->check_in_at) {
            return response()->json(['message' => 'You have already checked in.'], 400);
        }

        // Check window
        if ($dutySchedule->check_in_window_start && $dutySchedule->check_in_window_end) {
            $nowStr = now()->format('H:i:s');

            // Normalize for comparison
            $start = strlen($dutySchedule->check_in_window_start) === 5
                ? $dutySchedule->check_in_window_start . ':00'
                : $dutySchedule->check_in_window_start;

            $end = strlen($dutySchedule->check_in_window_end) === 5
                ? $dutySchedule->check_in_window_end . ':00'
                : $dutySchedule->check_in_window_end;

            if ($nowStr < $start || $nowStr > $end) {
                // Format for Humans (e.g. "4:50 PM")
                $niceStart = Carbon::parse($start)->format('g:i A');
                $niceEnd = Carbon::parse($end)->format('g:i A');
                $niceNow = now()->format('g:i A');

                return response()->json([
                    'message' => "Check-in is only available between {$niceStart} and {$niceEnd}. It is currently {$niceNow}.",
                    'window_start' => $start,
                    'window_end' => $end
                ], 400);
            }
        }

        $dutyAssignment->update(['check_in_at' => now()]);

        return response()->json($dutyAssignment->fresh(['dutySchedule', 'officer']));
    }

    /**
     * CHECK OUT - USER FRIENDLY
     */
    public function checkOut(Organization $organization, DutySchedule $dutySchedule, DutyAssignment $dutyAssignment)
    {
        if ($dutyAssignment->officer_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!$dutyAssignment->check_in_at) {
            return response()->json(['message' => 'You must check in first.'], 400);
        }

        if ($dutyAssignment->check_out_at) {
            return response()->json(['message' => 'You have already checked out.'], 400);
        }

        // Check window
        if ($dutySchedule->check_out_window_start && $dutySchedule->check_out_window_end) {
            $nowStr = now()->format('H:i:s');

            $start = strlen($dutySchedule->check_out_window_start) === 5
                ? $dutySchedule->check_out_window_start . ':00'
                : $dutySchedule->check_out_window_start;

            $end = strlen($dutySchedule->check_out_window_end) === 5
                ? $dutySchedule->check_out_window_end . ':00'
                : $dutySchedule->check_out_window_end;

            if ($nowStr < $start || $nowStr > $end) {
                // Format for Humans
                $niceStart = Carbon::parse($start)->format('g:i A');
                $niceEnd = Carbon::parse($end)->format('g:i A');
                $niceNow = now()->format('g:i A');

                return response()->json([
                    'message' => "You can only check out between {$niceStart} and {$niceEnd}. It is currently {$niceNow}.",
                    'window_start' => $start,
                    'window_end' => $end
                ], 400);
            }
        }

        $dutyAssignment->update([
            'check_out_at' => now(),
            'status' => 'completed',
        ]);

        return response()->json($dutyAssignment->fresh(['dutySchedule', 'officer']));
    }
}
