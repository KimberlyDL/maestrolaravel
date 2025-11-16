<?php

namespace App\Http\Controllers\Duty;

use App\Http\Controllers\Controller;
use App\Models\DutySchedule;
use App\Models\DutyAssignment;
use App\Models\Organization;
use App\Services\DutyScheduleService;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DutyScheduleController extends Controller
{
    public function __construct(
        private readonly DutyScheduleService $dutyService
    ) {}

    /**
     * Get all duty schedules for organization (LIST VIEW)
     */
    public function index(Request $request, Organization $organization)
    {
        $this->authorize('viewDutySchedules', $organization);

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

        $schedules = $query->orderBy('date', 'desc')
            ->orderBy('start_time', 'asc')
            ->get();

        // Add assignment counts to each schedule
        $schedules->each(function ($schedule) {
            $schedule->assigned_count = $schedule->assignments
                ->whereIn('status', ['assigned', 'confirmed', 'completed'])
                ->count();
        });

        return response()->json($schedules);
    }

    /**
     * Get calendar view of duty schedules (CALENDAR VIEW - FIXED)
     */
    public function calendar(Request $request, Organization $organization)
    {
        $this->authorize('viewDutySchedules', $organization);

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
                ->whereIn('status', ['assigned', 'confirmed', 'completed'])
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

    /**
     * Get single duty schedule details
     */
    public function show(Request $request, Organization $organization, DutySchedule $dutySchedule)
    {
        $this->authorize('viewDutySchedules', $organization);

        $dutySchedule->load([
            'assignments.officer:id,name,email,avatar,avatar_url',
            'assignments.assigner:id,name',
            'creator:id,name'
        ]);

        // Add assignment count
        $dutySchedule->assigned_count = $dutySchedule->assignments
            ->whereIn('status', ['assigned', 'confirmed', 'completed'])
            ->count();

        return response()->json($dutySchedule);
    }

    /**
     * Create duty schedule (with activity logging)
     */
    public function store(Request $request, Organization $organization)
    {
        $this->authorize('manageDutySchedules', $organization);

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
        ]);

        $schedule = $this->dutyService->createSchedule($organization->id, $data, auth()->id());

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

        return response()->json($schedule->load([
            'assignments.officer:id,name,email,avatar,avatar_url',
            'creator:id,name'
        ]), 201);
    }

    /**
     * Update duty schedule
     */
    public function update(Request $request, Organization $organization, DutySchedule $dutySchedule)
    {
        $this->authorize('manageDutySchedules', $organization);

        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'date' => 'sometimes|date',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i',
            'location' => 'nullable|string|max:255',
            'required_officers' => 'sometimes|integer|min:1|max:50',
            'status' => 'sometimes|in:draft,published,completed,cancelled',
        ]);

        $dutySchedule->update($data);

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

    /**
     * Delete duty schedule
     */
    public function destroy(Organization $organization, DutySchedule $dutySchedule)
    {
        $this->authorize('manageDutySchedules', $organization);

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

    /**
     * Duplicate duty schedule
     */
    public function duplicate(Request $request, Organization $organization, DutySchedule $dutySchedule)
    {
        $this->authorize('manageDutySchedules', $organization);

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

    /**
     * Get member's personal duty statistics
     */
    public function memberStatistics(Request $request, Organization $organization)
    {
        $this->authorize('viewDutySchedules', $organization);

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
            'monthly_breakdown' => $monthlyBreakdown,
            'recent_duties' => $recentDuties
        ]);
    }

    /**
     * Get duty statistics (enhanced with time series data)
     */
    public function statistics(Request $request, Organization $organization)
    {
        $this->authorize('viewDutySchedules', $organization);

        $startDate = $request->input('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->input('end_date', now()->endOfMonth()->toDateString());

        $stats = $this->dutyService->getStatistics($organization->id, $startDate, $endDate);

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
