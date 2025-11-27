<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class NotificationService
{
    /**
     * Core method to create a notification in the database
     */
    public function send($userId, $title, $message, $type, $notifiable = null, $actionUrl = null, $priority = 'normal', $orgId = null)
    {
        return Notification::create([
            'user_id' => $userId,
            'organization_id' => $orgId,
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'notifiable_type' => $notifiable ? get_class($notifiable) : null,
            'notifiable_id' => $notifiable ? $notifiable->id : null,
            'action_url' => $actionUrl,
            'priority' => $priority,
            'is_read' => false,
        ]);
    }

    /**
     * Below are the specific methods called by your Controllers
     */

    public function notifyDutyAssigned($assignment)
    {
        $schedule = $assignment->dutySchedule;
        
        $this->send(
            $assignment->officer_id,
            'New Duty Assigned',
            "You have been assigned to duty: {$schedule->title} on {$schedule->date}",
            'duty.assigned',
            $assignment,
            "/duty/schedules/{$schedule->id}",
            'high',
            $schedule->organization_id
        );
    }

    public function notifyDutyUpdated($schedule, $changes)
    {
        // Notify all officers assigned to this schedule
        foreach ($schedule->assignments as $assignment) {
            $this->send(
                $assignment->officer_id,
                'Duty Schedule Updated',
                "The duty '{$schedule->title}' has been updated.",
                'duty.updated',
                $schedule,
                "/duty/schedules/{$schedule->id}",
                'normal',
                $schedule->organization_id
            );
        }
    }

    public function notifyDutyCancelled($schedule)
    {
        foreach ($schedule->assignments as $assignment) {
            $this->send(
                $assignment->officer_id,
                'Duty Cancelled',
                "The duty '{$schedule->title}' scheduled for {$schedule->date} has been cancelled.",
                'duty.cancelled',
                $schedule,
                null, // No link for cancelled items
                'urgent',
                $schedule->organization_id
            );
        }
    }

    public function notifySwapRequested($swapRequest)
    {
        $assignment = $swapRequest->dutyAssignment;
        $schedule = $assignment->dutySchedule;

        // If directed to a specific person
        if ($swapRequest->to_officer_id) {
            $this->send(
                $swapRequest->to_officer_id,
                'Duty Swap Request',
                "{$swapRequest->fromOfficer->name} wants to swap duties with you for {$schedule->date}.",
                'duty.swap_requested',
                $swapRequest,
                "/duty/swaps",
                'high',
                $schedule->organization_id
            );
        } else {
            // If open swap (notify admins or broadcast - keeping simple for now)
            // You might want to notify organization admins here
        }
    }

    public function notifySwapAccepted($swapRequest)
    {
        $this->send(
            $swapRequest->from_officer_id,
            'Swap Request Accepted',
            "Your swap request for {$swapRequest->dutyAssignment->dutySchedule->date} was accepted.",
            'duty.swap_accepted',
            $swapRequest,
            "/duty/swaps",
            'normal',
            $swapRequest->dutyAssignment->dutySchedule->organization_id
        );
    }

    public function notifySwapDeclined($swapRequest)
    {
        $this->send(
            $swapRequest->from_officer_id,
            'Swap Request Declined',
            "Your swap request was declined.",
            'duty.swap_declined',
            $swapRequest,
            "/duty/swaps",
            'normal',
            $swapRequest->dutyAssignment->dutySchedule->organization_id
        );
    }
    
    public function notifySwapRejected($swapRequest)
    {
         $this->send(
            $swapRequest->from_officer_id,
            'Swap Request Rejected by Admin',
            "Your swap request was rejected by an admin.",
            'duty.swap_rejected',
            $swapRequest,
            "/duty/swaps",
            'high',
            $swapRequest->dutyAssignment->dutySchedule->organization_id
        );
    }

    public function notifySwapApproved($swapRequest)
    {
        $this->send(
            $swapRequest->from_officer_id,
            'Swap Request Approved',
            "Your swap request has been approved by an admin.",
            'duty.swap_approved',
            $swapRequest,
            "/duty/swaps",
            'high',
            $swapRequest->dutyAssignment->dutySchedule->organization_id
        );
    }

    public function notifyAssignmentConfirmed($assignment)
    {
        // Notify Admins (Logic to find admins would go here, skipping for brevity)
    }

    public function notifyAssignmentDeclined($assignment)
    {
        // Notify Admins
    }
}