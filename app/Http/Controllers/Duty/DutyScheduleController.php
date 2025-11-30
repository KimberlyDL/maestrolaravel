<?php

namespace App\Http\Controllers\Duty;

use App\Http\Controllers\Controller;
use App\Models\DutySchedule;
use App\Models\DutyAssignment;
use App\Models\Organization;
use App\Services\DutyScheduleService;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Carbon\Carbon;

class DutyScheduleController extends Controller
{
    public function __construct(
        private readonly DutyScheduleService $dutyService,
        private readonly NotificationService $notificationService
    ) {}

    // ... [index, calendar, show methods remain unchanged] ...

    public function index(Request $request, Organization $organization)
    {
        // $this->authorize('viewDutySchedules', $organization);

        $query = DutySchedule::forOrganization($organization->id)
            ->with([
                'assignments.officer:id,name,email,avatar,avatar_url',
                'creator:id,name'
            ]);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by date range
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('date', [$request->start_date, $request->end_date]);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%");
            });
        }

        //     $schedules = $query->orderBy('date', 'desc')
        //         ->orderBy('start_time', 'asc')
        //         ->get();

        //     // Add assignment counts to each schedule
        //     $schedules->each(function ($schedule) {
        //         $schedule->assigned_count = $schedule->assignments
        //             ->whereIn('status', ['assigned', 'confirmed', 'completed'])
        //             ->count();
        //     });

        //     return response()->json($schedules);
        // }

        $schedules = $query->orderBy('date', 'desc')
            ->orderBy('start_time', 'asc')
            ->get();

        // FIX 1: Update assignment counts to include 'completed' and 'no_show' statuses
        $schedules->each(function ($schedule) {
            $schedule->assigned_count = $schedule->assignments
                ->whereIn('status', ['assigned', 'confirmed', 'completed', 'no_show']) // UPDATED
                ->count();
        });

        return response()->json($schedules);
    }

    public function calendar(Request $request, Organization $organization)
    {
        // $this->authorize('viewDutySchedules', $organization);

        $startDate = $request->input('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->input('end_date', now()->endOfMonth()->toDateString());

        $schedules = DutySchedule::forOrganization($organization->id)
            ->whereBetween('date', [$startDate, $endDate])
            ->with([
                'assignments.officer:id,name,email,avatar,avatar_url'
            ])
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        // Format for FullCalendar
        $events = $schedules->map(function ($schedule) {
            $assignedCount = $schedule->assignments
                ->whereIn('status', ['assigned', 'confirmed', 'completed', 'no_show'])
                ->count();

            return [
                'id' => $schedule->id,
                'title' => $schedule->title,
                'description' => $schedule->description,
                'start' => $schedule->date . 'T' . $schedule->start_time,
                'end' => $schedule->date . 'T' . $schedule->end_time,
                'date' => $schedule->date,
                'start_time' => $schedule->start_time,
                'end_time' => $schedule->end_time,
                'location' => $schedule->location,
                'status' => $schedule->status,
                'required_officers' => $schedule->required_officers,
                'assigned_count' => $assignedCount,
                'assignments' => $schedule->assignments->map(function ($assignment) {
                    return [
                        'id' => $assignment->id,
                        'officer_id' => $assignment->officer_id,
                        'officer' => $assignment->officer,
                        'status' => $assignment->status,
                    ];
                }),
            ];
        });

        return response()->json($events);
    }


    //     public function calendar(Request $request, Organization $organization)
    //     {
    // // ...
    //         // Format for FullCalendar
    //         $events = $schedules->map(function ($schedule) {
    //             // FIX 1: Update assignedCount calculation to include 'completed' and 'no_show' statuses
    //             $assignedCount = $schedule->assignments
    //                 ->whereIn('status', ['assigned', 'confirmed', 'completed', 'no_show']) // UPDATED
    //                 ->count();

    //             return [
    // // ...
    //                 'required_officers' => $schedule->required_officers,
    //                 'assigned_count' => $assignedCount,
    //                 'assignments' => $schedule->assignments->map(function ($assignment) {
    // // ...
    //     }

    public function show(Request $request, Organization $organization, DutySchedule $dutySchedule)
    {
        // $this->authorize('viewDutySchedules', $organization);

        $dutySchedule->load([
            'assignments.officer:id,name,email,avatar,avatar_url',
            'assignments.assigner:id,name',
            'creator:id,name'
        ]);

        // Add assignment count
        // $dutySchedule->assigned_count = $dutySchedule->assignments
        //     ->whereIn('status', ['assigned', 'confirmed', 'completed'])
        //     ->count();

        $dutySchedule->assigned_count = $dutySchedule->assignments
            ->whereIn('status', ['assigned', 'confirmed', 'completed', 'no_show']) // UPDATED
            ->count();

        return response()->json($dutySchedule);
    }

    /**
     * Create duty schedule (with activity logging AND Notifications)
     */
    public function store(Request $request, Organization $organization)
    {
        // $this->authorize('manageDutySchedules', $organization);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'date' => 'required|date|after_or_equal:today',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'location' => 'nullable|string|max:255',
            'required_officers' => 'required|integer|min:1|max:50',
            'status' => 'required|in:draft,published',
            'recurrence_type' => 'required|in:none,daily,weekly,biweekly,monthly',
            'recurrence_days' => 'nullable|array',
            'recurrence_days.*' => 'integer|min:0|max:6',
            'recurrence_end_date' => 'nullable|date|after:date',
            'officer_ids' => 'nullable|array',
            'officer_ids.*' => 'exists:users,id',

            'check_in_window_start' => 'nullable|date_format:H:i',
            'check_in_window_end' => 'nullable|date_format:H:i',
            'check_out_window_start' => 'nullable|date_format:H:i',
            'check_out_window_end' => 'nullable|date_format:H:i',
        ]);

        $schedule = $this->dutyService->createSchedule($organization->id, $data, auth()->id());

        // Reload to get assignments
        $schedule->load(['assignments.officer']);

        // Log activity
        ActivityLogger::log(
            $organization->id,
            'duty.schedule.created',
            DutySchedule::class,
            $schedule->id,
            [
                'title' => $schedule->title,
                'date' => $schedule->date,
                'officers_count' => count($data['officer_ids'] ?? [])
            ],
            auth()->user()->name . ' created duty schedule: ' . $schedule->title
        );

        // --- NEW: Notify Assigned Officers ---
        if ($schedule->status === 'published' && $schedule->assignments->isNotEmpty()) {
            foreach ($schedule->assignments as $assignment) {
                $this->notificationService->send(
                    $assignment->officer_id,
                    'New Duty Assignment',
                    "You have been assigned to '{$schedule->title}' on {$schedule->date}",
                    'duty.assigned',
                    $schedule,
                    "/orgs/{$organization->id}/duties/{$schedule->id}",
                    'high',
                    $organization->id
                );
            }
        }

        return response()->json($schedule->load([
            'assignments.officer:id,name,email,avatar,avatar_url',
            'creator:id,name'
        ]), 201);
    }

    /**
     * Synchronize duty assignments based on incoming officer IDs.
     * This is required when updating a schedule to reflect changes in assigned officers.
     */
    private function syncOfficersOnUpdate(DutySchedule $dutySchedule, array $newOfficerIds, int $assignedBy)
    {
        // 1. Get current assignments (excluding declined, as they can be reassigned)
        $currentAssignments = $dutySchedule->assignments()
            ->whereIn('status', ['assigned', 'confirmed', 'completed', 'no_show'])
            ->get();
        $currentOfficerIds = $currentAssignments->pluck('officer_id')->toArray();

        // 2. Identify officers to remove (present currently but not in new list)
        $officersToRemove = array_diff($currentOfficerIds, $newOfficerIds);

        // 3. Identify officers to add (in new list but not present currently)
        $officersToAdd = array_diff($newOfficerIds, $currentOfficerIds);

        // Remove old assignments
        if (!empty($officersToRemove)) {
            $dutySchedule->assignments()
                ->whereIn('officer_id', $officersToRemove)
                ->delete();
        }

        // Add new assignments
        if (!empty($officersToAdd)) {
            $assignmentsToCreate = collect($officersToAdd)->map(function ($officerId) use ($dutySchedule, $assignedBy) {
                return [
                    'duty_schedule_id' => $dutySchedule->id,
                    'officer_id' => $officerId,
                    'status' => 'assigned',
                    'assigned_by' => $assignedBy,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            })->toArray();

            DutyAssignment::insert($assignmentsToCreate);
        }
    }

    /**
     * Update duty schedule
     */
    // public function update(Request $request, Organization $organization, DutySchedule $dutySchedule)
    // {
    //     // $this->authorize('manageDutySchedules', $organization);

    //     $data = $request->validate([
    //         'title' => 'sometimes|string|max:255',
    //         'description' => 'nullable|string',
    //         'date' => 'sometimes|date',
    //         'start_time' => 'sometimes|date_format:H:i,H:i:s', // UPDATED
    //         'end_time' => 'sometimes|date_format:H:i,H:i:s',
    //         'location' => 'nullable|string|max:255',
    //         'required_officers' => 'sometimes|integer|min:1|max:50',
    //         'status' => 'sometimes|in:draft,published,completed,cancelled',

    //         'check_in_window_start' => 'nullable|date_format:H:i',
    //         'check_in_window_end' => 'nullable|date_format:H:i',
    //         'check_out_window_start' => 'nullable|date_format:H:i',
    //         'check_out_window_end' => 'nullable|date_format:H:i',
    //     ]);

    //     // Normalize time format if needed
    //     if (isset($data['start_time']) && strlen($data['start_time']) === 5) {
    //         $data['start_time'] .= ':00';
    //     }
    //     if (isset($data['end_time']) && strlen($data['end_time']) === 5) {
    //         $data['end_time'] .= ':00';
    //     }

    //     $originalData = $dutySchedule->only(array_keys($data));

    //     $dutySchedule->update($data);

    //     // Detect what changed
    //     $changes = [];
    //     foreach ($data as $key => $value) {
    //         if ($originalData[$key] != $value) {
    //             $changes[$key] = $value;
    //         }
    //     }

    //     // Notify if significant changes
    //     if (!empty($changes) && $dutySchedule->status !== 'draft') {
    //         // Get all assigned officers
    //         foreach ($dutySchedule->assignments as $assignment) {
    //             $this->notificationService->send(
    //                 $assignment->officer_id,
    //                 'Duty Updated',
    //                 "The duty '{$dutySchedule->title}' has been updated.",
    //                 'duty.updated',
    //                 $dutySchedule,
    //                 "/orgs/{$organization->id}/duties/{$dutySchedule->id}",
    //                 'normal',
    //                 $organization->id
    //             );
    //         }
    //     }

    //     // Log activity
    //     ActivityLogger::log(
    //         $organization->id,
    //         'duty.schedule.updated',
    //         DutySchedule::class,
    //         $dutySchedule->id,
    //         ['updates' => array_keys($data)],
    //         auth()->user()->name . ' updated duty schedule: ' . $dutySchedule->title
    //     );

    //     return response()->json($dutySchedule->load([
    //         'assignments.officer:id,name,email,avatar,avatar_url',
    //         'creator:id,name'
    //     ]));
    // }

    /**
     * Update duty schedule
     */
    public function update(Request $request, Organization $organization, DutySchedule $dutySchedule)
    {
        // $this->authorize('manageDutySchedules', $organization);

        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'date' => 'sometimes|date',
            'start_time' => 'sometimes|date_format:H:i,H:i:s', // Accepts both H:i and H:i:s
            'end_time' => 'sometimes|date_format:H:i,H:i:s',
            'location' => 'nullable|string|max:255',
            'required_officers' => 'sometimes|integer|min:1|max:50',
            'status' => 'sometimes|in:draft,published,completed,cancelled',

            'officer_ids' => 'nullable|array', // NEW
            'officer_ids.*' => 'exists:users,id', // NEW

            'check_in_window_start' => 'nullable|date_format:H:i',
            'check_in_window_end' => 'nullable|date_format:H:i',
            'check_out_window_start' => 'nullable|date_format:H:i',
            'check_out_window_end' => 'nullable|date_format:H:i',
        ]);

        // Normalize time format if needed
        if (isset($data['start_time']) && strlen($data['start_time']) === 5) {
            $data['start_time'] .= ':00';
        }
        if (isset($data['end_time']) && strlen($data['end_time']) === 5) {
            $data['end_time'] .= ':00';
        }

        $originalData = $dutySchedule->only(array_keys($data));

        // Separate officer_ids from the schedule data before updating the schedule model
        $officerIds = $data['officer_ids'] ?? null;
        if ($officerIds !== null) {
            $officerChanges = true;
            unset($data['officer_ids']);
        } else {
            $officerChanges = false;
        }

        $dutySchedule->update($data);

        // FIX: Synchronize officer assignments if officer_ids were provided in the request
        if ($officerIds !== null) {
            $this->syncOfficersOnUpdate($dutySchedule, $officerIds, auth()->id());
        }

        // Detect what changed
        $changes = [];
        foreach ($data as $key => $value) {
            if (isset($originalData[$key]) && $originalData[$key] != $value) {
                $changes[$key] = $value;
            }
        }

        // Reload assignments for accurate notifications and response
        $dutySchedule->load('assignments');

        // Notify if significant changes (schedule details or officer list)
        if (!empty($changes) || $officerChanges) {
            // Get all assigned officers
            foreach ($dutySchedule->assignments as $assignment) {
                $this->notificationService->send(
                    $assignment->officer_id,
                    'Duty Updated',
                    "The duty '{$dutySchedule->title}' has been updated.",
                    'duty.updated',
                    $dutySchedule,
                    "/orgs/{$organization->id}/duties/{$dutySchedule->id}",
                    'normal',
                    $organization->id
                );
            }
        }

        // Log activity
        ActivityLogger::log(
            $organization->id,
            'duty.schedule.updated',
            DutySchedule::class,
            $dutySchedule->id,
            ['updates' => array_keys($data)],
            auth()->user()->name . ' updated duty schedule: ' . $dutySchedule->title
        );

        return response()->json($dutySchedule->load([
            'assignments.officer:id,name,email,avatar,avatar_url',
            'creator:id,name'
        ]));
    }

    public function destroy(Organization $organization, DutySchedule $dutySchedule)
    {
        // $this->authorize('manageDutySchedules', $organization);

        $title = $dutySchedule->title;
        $dutySchedule->delete();

        // Log activity
        ActivityLogger::log(
            $organization->id,
            'duty.schedule.deleted',
            DutySchedule::class,
            $dutySchedule->id,
            ['title' => $title],
            auth()->user()->name . ' deleted duty schedule: ' . $title
        );

        return response()->json(['message' => 'Duty schedule deleted successfully']);
    }

    public function duplicate(Request $request, Organization $organization, DutySchedule $dutySchedule)
    {
        // $this->authorize('manageDutySchedules', $organization);

        $data = $request->validate([
            'date' => 'required|date|after_or_equal:today',
            'copy_assignments' => 'boolean',
        ]);

        $newSchedule = $dutySchedule->replicate();
        $newSchedule->date = $data['date'];
        $newSchedule->status = 'draft';
        $newSchedule->created_by = auth()->id();
        $newSchedule->save();

        // Copy assignments if requested
        if ($data['copy_assignments'] ?? false) {
            foreach ($dutySchedule->assignments as $assignment) {
                DutyAssignment::create([
                    'duty_schedule_id' => $newSchedule->id,
                    'officer_id' => $assignment->officer_id,
                    'assigned_by' => auth()->id(),
                    'status' => 'assigned',
                    'notes' => 'Duplicated from ' . $dutySchedule->date,
                ]);
            }
        }

        // Log activity
        ActivityLogger::log(
            $organization->id,
            'duty.schedule.duplicated',
            DutySchedule::class,
            $newSchedule->id,
            [
                'original_id' => $dutySchedule->id,
                'new_date' => $data['date'],
                'copied_assignments' => $data['copy_assignments'] ?? false,
            ],
            auth()->user()->name . ' duplicated duty schedule: ' . $dutySchedule->title
        );

        return response()->json($newSchedule->load([
            'assignments.officer:id,name,email,avatar,avatar_url',
            'creator:id,name'
        ]), 201);
    }


    public function memberStatistics(Request $request, Organization $organization)
    {
        // $this->authorize('viewDutySchedules', $organization);

        $startDate = $request->input('start_date', now()->subMonths(3)->toDateString());
        $endDate = $request->input('end_date', now()->toDateString());
        $userId = auth()->id();

        // Get all assignments for this user in date range
        $assignments = DutyAssignment::whereHas('dutySchedule', function ($q) use ($organization, $startDate, $endDate) {
            $q->where('organization_id', $organization->id)
                ->whereBetween('date', [$startDate, $endDate]);
        })
            ->where('officer_id', $userId)
            ->with('dutySchedule')
            ->get();

        $total = $assignments->count();
        $confirmed = $assignments->where('status', 'confirmed')->count();
        $completed = $assignments->where('status', 'completed')->count();
        $declined = $assignments->where('status', 'declined')->count();
        $noShow = $assignments->where('status', 'no_show')->count();
        $pending = $assignments->where('status', 'assigned')->count();

        // Calculate hours worked
        $hoursWorked = $assignments->where('status', 'completed')->sum(function ($assignment) {
            $start = Carbon::parse($assignment->dutySchedule->start_time);
            $end = Carbon::parse($assignment->dutySchedule->end_time);
            return $end->diffInHours($start);
        });

        // Completion rate
        $completionRate = $total > 0 ? round(($completed / $total) * 100, 1) : 0;

        // Reliability score
        $reliabilityScore = $total > 0 ? round((($completed - $noShow) / $total) * 100, 1) : 0;
        $reliabilityScore = max(0, min(100, $reliabilityScore));

        // NEW: Check-in/out stats for member
        $assignmentsWithCheckIn = $assignments->filter(fn($a) => $a->check_in_at);
        $checkInRate = $completed > 0
            ? round(($assignmentsWithCheckIn->count() / $completed) * 100, 1)
            : 0;

        $actualDurations = $assignmentsWithCheckIn
            ->filter(fn($a) => $a->check_out_at)
            ->map(function ($a) {
                $start = Carbon::parse($a->check_in_at);
                $end = Carbon::parse($a->check_out_at);
                return $end->diffInHours($start, true);
            });

        $avgActualDuration = $actualDurations->isNotEmpty()
            ? round($actualDurations->average(), 1)
            : 0;

        $onTimeCount = $assignmentsWithCheckIn->filter(function ($a) {
            $scheduledStart = Carbon::parse($a->dutySchedule->date->toDateString() . ' ' . $a->dutySchedule->start_time);
            $checkIn = Carbon::parse($a->check_in_at);
            $diffMinutes = $scheduledStart->diffInMinutes($checkIn, false);
            return $diffMinutes >= -15 && $diffMinutes <= 15;
        })->count();

        $onTimeRate = $assignmentsWithCheckIn->isNotEmpty()
            ? round(($onTimeCount / $assignmentsWithCheckIn->count()) * 100, 1)
            : 0;

        // Monthly breakdown
        $monthlyBreakdown = [];
        $currentMonth = Carbon::parse($startDate);
        $endMonth = Carbon::parse($endDate);

        while ($currentMonth->lte($endMonth)) {
            $monthStart = $currentMonth->copy()->startOfMonth()->toDateString();
            $monthEnd = $currentMonth->copy()->endOfMonth()->toDateString();

            $monthAssignments = $assignments->filter(function ($a) use ($monthStart, $monthEnd) {
                $date = $a->dutySchedule->date;
                return $date >= $monthStart && $date <= $monthEnd;
            });

            $monthTotal = $monthAssignments->count();
            $monthCompleted = $monthAssignments->where('status', 'completed')->count();
            $monthNoShow = $monthAssignments->where('status', 'no_show')->count();

            $monthlyBreakdown[] = [
                'month' => $currentMonth->format('M Y'),
                'total' => $monthTotal,
                'completed' => $monthCompleted,
                'no_show' => $monthNoShow,
                'completion_rate' => $monthTotal > 0 ? round(($monthCompleted / $monthTotal) * 100, 1) : 0
            ];

            $currentMonth->addMonth();
        }

        // Recent duties
        $recentDuties = $assignments
            ->sortByDesc(function ($a) {
                return $a->dutySchedule->date;
            })
            ->take(10)
            ->map(function ($assignment) {
                return [
                    'id' => $assignment->id,
                    'title' => $assignment->dutySchedule->title,
                    'date' => $assignment->dutySchedule->date,
                    'start_time' => $assignment->dutySchedule->start_time,
                    'end_time' => $assignment->dutySchedule->end_time,
                    'status' => $assignment->status
                ];
            })
            ->values();

        return response()->json([
            'total_assignments' => $total,
            'confirmed' => $confirmed,
            'completed' => $completed,
            'declined' => $declined,
            'no_show' => $noShow,
            'pending' => $pending,
            'hours_worked' => round($hoursWorked, 1),
            'completion_rate' => $completionRate,
            'reliability_score' => $reliabilityScore,
            'check_in_rate' => $checkInRate,
            'avg_actual_duration' => $avgActualDuration,
            'on_time_rate' => $onTimeRate,
            'monthly_breakdown' => $monthlyBreakdown,
            'recent_duties' => $recentDuties
        ]);
    }
    // public function memberStatistics(Request $request, Organization $organization)
    // {
    // $this->authorize('viewDutySchedules', $organization);

    //     $startDate = $request->input('start_date', now()->subMonths(3)->toDateString());
    //     $endDate = $request->input('end_date', now()->toDateString());
    //     $userId = auth()->id();

    //     // Get all assignments for this user in date range
    //     $assignments = DutyAssignment::whereHas('dutySchedule', function ($q) use ($organization, $startDate, $endDate) {
    //         $q->where('organization_id', $organization->id)
    //             ->whereBetween('date', [$startDate, $endDate]);
    //     })
    //         ->where('officer_id', $userId)
    //         ->with('dutySchedule')
    //         ->get();

    //     $total = $assignments->count();
    //     $confirmed = $assignments->where('status', 'confirmed')->count();
    //     $completed = $assignments->where('status', 'completed')->count();
    //     $declined = $assignments->where('status', 'declined')->count();
    //     $noShow = $assignments->where('status', 'no_show')->count();
    //     $pending = $assignments->where('status', 'assigned')->count();

    //     // Calculate hours worked
    //     $hoursWorked = $assignments->where('status', 'completed')->sum(function ($assignment) {
    //         $start = Carbon::parse($assignment->dutySchedule->start_time);
    //         $end = Carbon::parse($assignment->dutySchedule->end_time);
    //         return $end->diffInHours($start);
    //     });

    //     // Completion rate
    //     $completionRate = $total > 0 ? round(($completed / $total) * 100, 1) : 0;

    //     // Reliability score
    //     $reliabilityScore = $total > 0 ? round((($completed - $noShow) / $total) * 100, 1) : 0;
    //     $reliabilityScore = max(0, min(100, $reliabilityScore));

    //     // Monthly breakdown
    //     $monthlyBreakdown = [];
    //     $currentMonth = Carbon::parse($startDate);
    //     $endMonth = Carbon::parse($endDate);

    //     while ($currentMonth->lte($endMonth)) {
    //         $monthStart = $currentMonth->copy()->startOfMonth()->toDateString();
    //         $monthEnd = $currentMonth->copy()->endOfMonth()->toDateString();

    //         $monthAssignments = $assignments->filter(function ($a) use ($monthStart, $monthEnd) {
    //             $date = $a->dutySchedule->date;
    //             return $date >= $monthStart && $date <= $monthEnd;
    //         });

    //         $monthTotal = $monthAssignments->count();
    //         $monthCompleted = $monthAssignments->where('status', 'completed')->count();
    //         $monthNoShow = $monthAssignments->where('status', 'no_show')->count();

    //         $monthlyBreakdown[] = [
    //             'month' => $currentMonth->format('M Y'),
    //             'total' => $monthTotal,
    //             'completed' => $monthCompleted,
    //             'no_show' => $monthNoShow,
    //             'completion_rate' => $monthTotal > 0 ? round(($monthCompleted / $monthTotal) * 100, 1) : 0
    //         ];

    //         $currentMonth->addMonth();
    //     }

    //     // Recent duties
    //     $recentDuties = $assignments
    //         ->sortByDesc(function ($a) {
    //             return $a->dutySchedule->date;
    //         })
    //         ->take(10)
    //         ->map(function ($assignment) {
    //             return [
    //                 'id' => $assignment->id,
    //                 'title' => $assignment->dutySchedule->title,
    //                 'date' => $assignment->dutySchedule->date,
    //                 'start_time' => $assignment->dutySchedule->start_time,
    //                 'end_time' => $assignment->dutySchedule->end_time,
    //                 'status' => $assignment->status
    //             ];
    //         })
    //         ->values();

    //     return response()->json([
    //         'total_assignments' => $total,
    //         'confirmed' => $confirmed,
    //         'completed' => $completed,
    //         'declined' => $declined,
    //         'no_show' => $noShow,
    //         'pending' => $pending,
    //         'hours_worked' => round($hoursWorked, 1),
    //         'completion_rate' => $completionRate,
    //         'reliability_score' => $reliabilityScore,
    //         'monthly_breakdown' => $monthlyBreakdown,
    //         'recent_duties' => $recentDuties
    //     ]);
    // }

    // public function statistics(Request $request, Organization $organization)
    // {
    // $this->authorize('viewDutySchedules', $organization);

    //     $startDate = $request->input('start_date', now()->startOfMonth()->toDateString());
    //     $endDate = $request->input('end_date', now()->endOfMonth()->toDateString());

    //     $stats = $this->dutyService->getStatistics($organization->id, $startDate, $endDate);

    //     // Add time series data for charts
    //     $timeSeries = [];
    //     $currentDate = Carbon::parse($startDate);
    //     $end = Carbon::parse($endDate);

    //     $days = $currentDate->diffInDays($end);
    //     $groupBy = $days > 30 ? 'week' : 'day';

    //     while ($currentDate->lte($end)) {
    //         $periodStart = $currentDate->copy()->toDateString();
    //         $periodEnd = $groupBy === 'week'
    //             ? $currentDate->copy()->addWeek()->toDateString()
    //             : $currentDate->copy()->toDateString();

    //         $periodSchedules = DutySchedule::forOrganization($organization->id)
    //             ->whereBetween('date', [$periodStart, $periodEnd])
    //             ->with('assignments')
    //             ->get();

    //         $periodAssignments = $periodSchedules->flatMap(fn($s) => $s->assignments);
    //         $totalAssignments = $periodAssignments->count();
    //         $completedAssignments = $periodAssignments->where('status', 'completed')->count();

    //         $totalRequired = $periodSchedules->sum('required_officers');
    //         $totalFilled = $periodSchedules->sum(function ($schedule) {
    //             return $schedule->assignments->whereIn('status', ['assigned', 'confirmed', 'completed'])->count();
    //         });

    //         $timeSeries[] = [
    //             'date' => $currentDate->format($groupBy === 'week' ? 'M d' : 'M d'),
    //             'completion_rate' => $totalAssignments > 0 ? round(($completedAssignments / $totalAssignments) * 100, 1) : 0,
    //             'fill_rate' => $totalRequired > 0 ? round(($totalFilled / $totalRequired) * 100, 1) : 0
    //         ];

    //         $currentDate = $groupBy === 'week' ? $currentDate->addWeek() : $currentDate->addDay();
    //     }

    //     $stats['time_series'] = $timeSeries;

    //     return response()->json($stats);
    // }


    // public function statistics(Request $request, Organization $organization)
    // {
    // $this->authorize('viewDutySchedules', $organization);

    //     $startDate = $request->input('start_date', now()->startOfMonth()->toDateString());
    //     $endDate = $request->input('end_date', now()->endOfMonth()->toDateString());

    //     $stats = $this->dutyService->getStatistics($organization->id, $startDate, $endDate);

    //     // Add check-in/out statistics
    //     $assignmentsWithCheckIn = DutyAssignment::whereHas('dutySchedule', function ($q) use ($organization, $startDate, $endDate) {
    //         $q->where('organization_id', $organization->id)
    //             ->whereBetween('date', [$startDate, $endDate]);
    //     })
    //         ->whereNotNull('check_in_at')
    //         ->with('dutySchedule')
    //         ->get();

    //     $totalCompleted = $assignmentsWithCheckIn->where('status', 'completed')->count();
    //     $checkInRate = $totalCompleted > 0
    //         ? round(($assignmentsWithCheckIn->count() / $totalCompleted) * 100, 1)
    //         : 0;

    //     // Calculate average actual duration (based on check-in/out)
    //     $actualDurations = $assignmentsWithCheckIn
    //         ->filter(fn($a) => $a->check_in_at && $a->check_out_at)
    //         ->map(function ($a) {
    //             $start = Carbon::parse($a->check_in_at);
    //             $end = Carbon::parse($a->check_out_at);
    //             return $end->diffInHours($start, true); // true for float result
    //         });

    //     $avgActualDuration = $actualDurations->isNotEmpty()
    //         ? round($actualDurations->average(), 1)
    //         : 0;

    //     // Calculate on-time rate (checked in within 15 minutes of scheduled start)
    //     $onTimeCount = $assignmentsWithCheckIn->filter(function ($a) {
    //         $scheduledStart = Carbon::parse($a->dutySchedule->date . ' ' . $a->dutySchedule->start_time);
    //         $checkIn = Carbon::parse($a->check_in_at);
    //         $diffMinutes = $scheduledStart->diffInMinutes($checkIn, false);
    //         return $diffMinutes >= -15 && $diffMinutes <= 15; // Within 15 minutes
    //     })->count();

    //     $onTimeRate = $assignmentsWithCheckIn->isNotEmpty()
    //         ? round(($onTimeCount / $assignmentsWithCheckIn->count()) * 100, 1)
    //         : 0;

    //     $stats['check_in_rate'] = $checkInRate;
    //     $stats['avg_actual_duration'] = $avgActualDuration;
    //     $stats['on_time_rate'] = $onTimeRate;

    //     // Add time series data for charts
    //     $timeSeries = [];
    //     $currentDate = Carbon::parse($startDate);
    //     $end = Carbon::parse($endDate);

    //     $days = $currentDate->diffInDays($end);
    //     $groupBy = $days > 30 ? 'week' : 'day';

    //     while ($currentDate->lte($end)) {
    //         $periodStart = $currentDate->copy()->toDateString();
    //         $periodEnd = $groupBy === 'week'
    //             ? $currentDate->copy()->addWeek()->toDateString()
    //             : $currentDate->copy()->toDateString();

    //         $periodSchedules = DutySchedule::forOrganization($organization->id)
    //             ->whereBetween('date', [$periodStart, $periodEnd])
    //             ->with('assignments')
    //             ->get();

    //         $periodAssignments = $periodSchedules->flatMap(fn($s) => $s->assignments);
    //         $totalAssignments = $periodAssignments->count();
    //         $completedAssignments = $periodAssignments->where('status', 'completed')->count();

    //         $totalRequired = $periodSchedules->sum('required_officers');
    //         $totalFilled = $periodSchedules->sum(function ($schedule) {
    //             return $schedule->assignments->whereIn('status', ['assigned', 'confirmed', 'completed'])->count();
    //         });

    //         $timeSeries[] = [
    //             'date' => $currentDate->format($groupBy === 'week' ? 'M d' : 'M d'),
    //             'completion_rate' => $totalAssignments > 0 ? round(($completedAssignments / $totalAssignments) * 100, 1) : 0,
    //             'fill_rate' => $totalRequired > 0 ? round(($totalFilled / $totalRequired) * 100, 1) : 0
    //         ];

    //         $currentDate = $groupBy === 'week' ? $currentDate->addWeek() : $currentDate->addDay();
    //     }

    //     $stats['time_series'] = $timeSeries;

    //     return response()->json($stats);
    // }

    public function statistics(Request $request, Organization $organization)
    {
        // $this->authorize('viewDutySchedules', $organization);

        $startDate = $request->input('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->input('end_date', now()->endOfMonth()->toDateString());

        $stats = $this->dutyService->getStatistics($organization->id, $startDate, $endDate);

        // Add check-in/out statistics
        $assignmentsWithCheckIn = DutyAssignment::whereHas('dutySchedule', function ($q) use ($organization, $startDate, $endDate) {
            $q->where('organization_id', $organization->id)
                ->whereBetween('date', [$startDate, $endDate]);
        })
            ->whereNotNull('check_in_at')
            ->with('dutySchedule')
            ->get();

        $totalCompleted = $stats['completed_assignments'];
        $checkInRate = $totalCompleted > 0
            ? round(($assignmentsWithCheckIn->count() / $totalCompleted) * 100, 1)
            : 0;

        // Calculate average actual duration (based on check-in/out)
        $actualDurations = $assignmentsWithCheckIn
            ->filter(fn($a) => $a->check_in_at && $a->check_out_at)
            ->map(function ($a) {
                $start = Carbon::parse($a->check_in_at);
                $end = Carbon::parse($a->check_out_at);
                return $end->diffInHours($start, true); // true for float result
            });

        $avgActualDuration = $actualDurations->isNotEmpty()
            ? round($actualDurations->average(), 1)
            : 0;

        // Calculate on-time rate (checked in within 15 minutes of scheduled start)
        $onTimeCount = $assignmentsWithCheckIn->filter(function ($a) {
            $scheduledStart = Carbon::parse($a->dutySchedule->date->toDateString() . ' ' . $a->dutySchedule->start_time);
            $checkIn = Carbon::parse($a->check_in_at);
            $diffMinutes = $scheduledStart->diffInMinutes($checkIn, false);
            return $diffMinutes >= -15 && $diffMinutes <= 15; // Within 15 minutes
        })->count();

        $onTimeRate = $assignmentsWithCheckIn->isNotEmpty()
            ? round(($onTimeCount / $assignmentsWithCheckIn->count()) * 100, 1)
            : 0;

        $stats['check_in_rate'] = $checkInRate;
        $stats['avg_actual_duration'] = $avgActualDuration;
        $stats['on_time_rate'] = $onTimeRate;

        // Add time series data for charts
        $timeSeries = [];
        $currentDate = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        $days = $currentDate->diffInDays($end);
        $groupBy = $days > 30 ? 'week' : 'day';

        while ($currentDate->lte($end)) {
            $periodStart = $currentDate->copy()->toDateString();
            $periodEnd = $groupBy === 'week'
                ? $currentDate->copy()->addWeek()->toDateString()
                : $currentDate->copy()->toDateString();

            $periodSchedules = DutySchedule::forOrganization($organization->id)
                ->whereBetween('date', [$periodStart, $periodEnd])
                ->with('assignments')
                ->get();

            $periodAssignments = $periodSchedules->flatMap(fn($s) => $s->assignments);
            $totalAssignments = $periodAssignments->count();
            $completedAssignments = $periodAssignments->where('status', 'completed')->count();

            $totalRequired = $periodSchedules->sum('required_officers');
            $totalFilled = $periodSchedules->sum(function ($schedule) {
                return $schedule->assignments->whereIn('status', ['assigned', 'confirmed', 'completed'])->count();
            });

            $timeSeries[] = [
                'date' => $currentDate->format($groupBy === 'week' ? 'M d' : 'M d'),
                'completion_rate' => $totalAssignments > 0 ? round(($completedAssignments / $totalAssignments) * 100, 1) : 0,
                'fill_rate' => $totalRequired > 0 ? round(($totalFilled / $totalRequired) * 100, 1) : 0
            ];

            $currentDate = $groupBy === 'week' ? $currentDate->addWeek() : $currentDate->addDay();
        }

        $stats['time_series'] = $timeSeries;

        return response()->json($stats);
    }
}
