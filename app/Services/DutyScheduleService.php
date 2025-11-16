<?php

namespace App\Services;

use App\Models\DutySchedule;
use App\Models\DutyAssignment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DutyScheduleService
{
    /**
     * Create duty schedule with optional recurrence
     */
    public function createSchedule(int $organizationId, array $data, int $creatorId)
    {
        return DB::transaction(function () use ($organizationId, $data, $creatorId) {
            $schedule = DutySchedule::create([
                'organization_id' => $organizationId,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'date' => $data['date'],
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'location' => $data['location'] ?? null,
                'status' => $data['status'],
                'required_officers' => $data['required_officers'],
                'recurrence_type' => $data['recurrence_type'],
                'recurrence_days' => $data['recurrence_days'] ?? null,
                'recurrence_end_date' => $data['recurrence_end_date'] ?? null,
                'created_by' => $creatorId,
            ]);

            // Assign officers if provided
            if (!empty($data['officer_ids'])) {
                $this->assignOfficers($schedule, $data['officer_ids'], $creatorId);
            }

            // Create recurring schedules if applicable
            if ($data['recurrence_type'] !== 'none') {
                $this->createRecurringSchedules($schedule);
            }

            return $schedule;
        });
    }

    /**
     * Assign officers to duty schedule
     */
    public function assignOfficers(DutySchedule $schedule, array $officerIds, int $assignerId, ?string $notes = null)
    {
        $assignments = [];

        foreach ($officerIds as $officerId) {
            // Check if not already assigned
            $exists = DutyAssignment::where('duty_schedule_id', $schedule->id)
                ->where('officer_id', $officerId)
                ->exists();

            if (!$exists) {
                $assignments[] = DutyAssignment::create([
                    'duty_schedule_id' => $schedule->id,
                    'officer_id' => $officerId,
                    'status' => 'assigned',
                    'notes' => $notes,
                    'assigned_by' => $assignerId,
                ]);
            }
        }

        return $assignments;
    }

    /**
     * Create recurring duty schedules
     */
    private function createRecurringSchedules(DutySchedule $baseSchedule)
    {
        if (!$baseSchedule->recurrence_end_date) {
            return;
        }

        $currentDate = Carbon::parse($baseSchedule->date);
        $endDate = Carbon::parse($baseSchedule->recurrence_end_date);
        $created = [];

        while ($currentDate->lte($endDate)) {
            $currentDate = $this->getNextRecurrenceDate($currentDate, $baseSchedule->recurrence_type, $baseSchedule->recurrence_days);

            if ($currentDate && $currentDate->lte($endDate)) {
                $newSchedule = $baseSchedule->replicate();
                $newSchedule->date = $currentDate->toDateString();
                $newSchedule->status = 'draft';
                $newSchedule->created_by = $baseSchedule->created_by;
                $newSchedule->save();
                $created[] = $newSchedule;

                // Copy assignments
                foreach ($baseSchedule->assignments as $assignment) {
                    DutyAssignment::create([
                        'duty_schedule_id' => $newSchedule->id,
                        'officer_id' => $assignment->officer_id,
                        'status' => 'assigned',
                        'assigned_by' => $assignment->assigned_by,
                    ]);
                }
            } else {
                break;
            }
        }

        return $created;
    }

    /**
     * Get next recurrence date
     */
    private function getNextRecurrenceDate(Carbon $currentDate, string $recurrenceType, ?array $recurrenceDays)
    {
        switch ($recurrenceType) {
            case 'daily':
                return $currentDate->addDay();

            case 'weekly':
                if ($recurrenceDays && count($recurrenceDays) > 0) {
                    // Find next day in recurrence_days
                    $currentDayOfWeek = $currentDate->dayOfWeek;
                    $nextDay = null;

                    foreach ($recurrenceDays as $day) {
                        if ($day > $currentDayOfWeek) {
                            $nextDay = $day;
                            break;
                        }
                    }

                    if ($nextDay === null) {
                        // Wrap to next week
                        $nextDay = $recurrenceDays[0];
                        $currentDate->addWeek();
                    }

                    return $currentDate->next($nextDay);
                } else {
                    return $currentDate->addWeek();
                }

            case 'biweekly':
                return $currentDate->addWeeks(2);

            case 'monthly':
                return $currentDate->addMonth();

            default:
                return null;
        }
    }

    /**
     * Get duty statistics - FIX 6: Ensure accurate filtering and complete data structure
     */
    public function getStatistics(int $organizationId, string $startDate, string $endDate)
    {
        // Get schedules in date range
        $schedules = DutySchedule::forOrganization($organizationId)
            ->inDateRange($startDate, $endDate)
            ->with(['assignments.officer'])
            ->get();

        $totalSchedules = $schedules->count();

        // Get all assignments from these schedules
        $allAssignments = $schedules->flatMap(fn($s) => $s->assignments);

        $totalAssignments = $allAssignments->count();
        $confirmedAssignments = $allAssignments->where('status', 'confirmed')->count();
        $completedAssignments = $allAssignments->where('status', 'completed')->count();
        $declinedAssignments = $allAssignments->where('status', 'declined')->count();
        $noShowAssignments = $allAssignments->where('status', 'no_show')->count();

        // Calculate fill rate (percentage of required positions filled)
        $totalRequired = $schedules->sum('required_officers');
        $totalFilled = $schedules->sum(function ($schedule) {
            return $schedule->assignments->whereIn('status', ['assigned', 'confirmed', 'completed'])->count();
        });
        $fillRate = $totalRequired > 0 ? round(($totalFilled / $totalRequired) * 100, 1) : 0;

        // Officer statistics
        $officerStats = [];
        $uniqueOfficerIds = $allAssignments->pluck('officer_id')->unique();

        foreach ($uniqueOfficerIds as $officerId) {
            $officerAssignments = $allAssignments->where('officer_id', $officerId);
            $officer = $officerAssignments->first()->officer;

            if (!$officer) continue;

            $total = $officerAssignments->count();
            $confirmed = $officerAssignments->where('status', 'confirmed')->count();
            $completed = $officerAssignments->where('status', 'completed')->count();
            $declined = $officerAssignments->where('status', 'declined')->count();
            $noShow = $officerAssignments->where('status', 'no_show')->count();

            $completionRate = $total > 0 ? round(($completed / $total) * 100, 1) : 0;

            $officerStats[] = [
                'officer_id' => $officerId,
                'officer_name' => $officer->name,
                'officer' => [
                    'id' => $officer->id,
                    'name' => $officer->name,
                ],
                'total' => $total,
                'assigned' => $total,
                'confirmed' => $confirmed,
                'completed' => $completed,
                'declined' => $declined,
                'no_show' => $noShow,
                'completion_rate' => $completionRate,
            ];
        }

        // Sort by completion rate descending
        usort($officerStats, fn($a, $b) => $b['completion_rate'] <=> $a['completion_rate']);

        // Calculate average duty duration
        $totalDuration = 0;
        $durationCount = 0;

        foreach ($schedules as $schedule) {
            $start = Carbon::parse($schedule->start_time);
            $end = Carbon::parse($schedule->end_time);
            $totalDuration += $end->diffInHours($start);
            $durationCount++;
        }

        $avgDuration = $durationCount > 0 ? round($totalDuration / $durationCount, 1) : 0;

        return [
            'total_schedules' => $totalSchedules,
            'total_assignments' => $totalAssignments,
            'confirmed_assignments' => $confirmedAssignments,
            'completed_assignments' => $completedAssignments,
            'declined_assignments' => $declinedAssignments,
            'no_show_assignments' => $noShowAssignments,
            'fill_rate' => $fillRate,
            'officers_active' => count($officerStats),
            'avg_duty_duration' => $avgDuration,
            'confirmation_rate' => $totalAssignments > 0 ? round(($confirmedAssignments / $totalAssignments) * 100, 1) : 0,
            'completion_rate' => $totalAssignments > 0 ? round(($completedAssignments / $totalAssignments) * 100, 1) : 0,
            'officer_stats' => $officerStats,
        ];
    }

    /**
     * Suggest officers for duty based on availability and workload
     */
    public function suggestOfficers(DutySchedule $schedule, int $count = 5)
    {
        // Implementation for intelligent officer suggestions
        // Consider: availability, past assignments, preferences, fairness
    }
}
