<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\DutyAssignment;
use App\Services\NotificationService;
use Carbon\Carbon;

class SendDutyReminders extends Command
{
    protected $signature = 'duty:send-reminders';
    protected $description = 'Send duty reminders 24 hours before scheduled duties';

    public function __construct(
        private readonly NotificationService $notificationService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Sending duty reminders...');

        // Get all confirmed assignments for tomorrow
        $tomorrow = Carbon::tomorrow();
        
        $assignments = DutyAssignment::with(['dutySchedule', 'officer'])
            ->whereHas('dutySchedule', function ($query) use ($tomorrow) {
                $query->where('date', $tomorrow->toDateString())
                    ->where('status', 'published');
            })
            ->where('status', 'confirmed')
            ->get();

        $count = 0;
        foreach ($assignments as $assignment) {
            try {
                $this->notificationService->notifyDutyReminder($assignment);
                $count++;
                
                $this->info(sprintf(
                    'Sent reminder to %s for duty: %s',
                    $assignment->officer->name,
                    $assignment->dutySchedule->title
                ));
            } catch (\Exception $e) {
                $this->error(sprintf(
                    'Failed to send reminder to %s: %s',
                    $assignment->officer->name,
                    $e->getMessage()
                ));
            }
        }

        $this->info(sprintf('Successfully sent %d duty reminders.', $count));

        return Command::SUCCESS;
    }
}