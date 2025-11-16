<?php

namespace App\Http\Controllers\Duty;

use App\Http\Controllers\Controller;
use App\Models\DutySchedule;
use App\Models\DutyAssignment;
use App\Models\Organization;
use App\Services\DutyScheduleService;
use Illuminate\Http\Request;

class DutyAssignmentController extends Controller
{
    public function __construct(
        private readonly DutyScheduleService $dutyService
    ) {}

    /**
     * Get current user's assignments
     */
    public function myAssignments(Request $request, Organization $organization)
    {
        $query = DutyAssignment::whereHas('dutySchedule', function ($q) use ($organization) {
            $q->where('organization_id', $organization->id);
        })
            ->where('officer_id', auth()->id())
            ->with([
                'dutySchedule' => function ($q) {
                    $q->select('id', 'title', 'description', 'date', 'start_time', 'end_time', 'location', 'required_officers', 'status');
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
     * Assign officer to duty
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

        return response()->json($dutyAssignment->fresh(['dutySchedule', 'officer']));
    }

    /**
     * Check in for duty
     */
    public function checkIn(Organization $organization, DutySchedule $dutySchedule, DutyAssignment $dutyAssignment)
    {
        if ($dutyAssignment->officer_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($dutyAssignment->status !== 'confirmed') {
            return response()->json(['message' => 'Assignment must be confirmed before checking in'], 400);
        }

        if ($dutyAssignment->check_in_at) {
            return response()->json(['message' => 'Already checked in'], 400);
        }

        $dutyAssignment->update([
            'check_in_at' => now(),
        ]);

        return response()->json($dutyAssignment->fresh(['dutySchedule', 'officer']));
    }

    /**
     * Check out from duty
     */
    public function checkOut(Organization $organization, DutySchedule $dutySchedule, DutyAssignment $dutyAssignment)
    {
        if ($dutyAssignment->officer_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!$dutyAssignment->check_in_at) {
            return response()->json(['message' => 'Must check in before checking out'], 400);
        }

        if ($dutyAssignment->check_out_at) {
            return response()->json(['message' => 'Already checked out'], 400);
        }

        $dutyAssignment->update([
            'check_out_at' => now(),
            'status' => 'completed',
        ]);

        return response()->json($dutyAssignment->fresh(['dutySchedule', 'officer']));
    }
}
