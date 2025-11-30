<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\DutyAssignment;
use Carbon\Carbon;

class UpdateDutyStatuses extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'duties:update-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Marks uncompleted, past-due assignments as "no_show" or "completed".';

    public function handle()
    {
        $now = now();
        $gracePeriodEnd = $now->subMinutes(15)->toDateTimeString();

        // 1. Find all relevant assignments (status: assigned or confirmed)
        $assignmentsToReview = DutyAssignment::whereIn('status', ['assigned', 'confirmed'])
            ->whereHas('dutySchedule', function ($q) use ($gracePeriodEnd) {
                // Assignments where CONCAT(date, ' ', end_time) is before the grace period end
                $q->whereRaw('CONCAT(date, " ", end_time) < ?', [$gracePeriodEnd]);
            })
            ->with('dutySchedule:id,title')
            ->get();

        $noShowCount = 0;
        $completedCount = 0;

        foreach ($assignmentsToReview as $assignment) {
            // Case A: Duty ended, no check-in -> NO_SHOW
            if (!$assignment->check_in_at) {
                $assignment->update(['status' => 'no_show']);
                $noShowCount++;
            }
            // Case B: Duty ended, checked in, but missed check-out -> COMPLETED
            elseif (!$assignment->check_out_at) {
                // If you want to enforce check-out, you might mark this as 'completed'
                // to give them credit for showing up, or a new status like 'partial_attendance'.
                // Sticking to your request, we mark as completed if checked in.
                $assignment->update(['status' => 'completed']);
                $completedCount++;
            }
        }

        $this->info("Duty statuses updated successfully.");
        $this->info(" - {$noShowCount} marked as 'no_show'.");
        $this->info(" - {$completedCount} marked as 'completed' (incomplete checkout).");
    }
}
