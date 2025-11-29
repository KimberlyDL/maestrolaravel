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

    /**
     * Notify Admins & Handlers about a new Join Request
     */
    public function notifyJoinRequestCreated($joinRequest)
    {
        // 1. Identify the Organization
        // We assume $joinRequest is a DB row object or model. If it's a raw object, we fetch the ID.
        $orgId = $joinRequest->organization_id;
        $requesterId = $joinRequest->user_id;

        // Fetch requester name for the message
        $requester = User::find($requesterId);
        $requesterName = $requester ? $requester->name : 'A user';

        // 2. Find Users to Notify:
        //    - Organization Admins (role = 'admin')
        //    - Users with 'approve_join_requests' permission (if permission system allows custom roles)

        // Get all members of the org
        $recipients = OrganizationUser::where('organization_id', $orgId)
            ->where(function ($query) {
                // Always notify admins
                $query->where('role', 'admin');

                // OR notify anyone with the specific permission (if you have a way to check permissions via DB)
                // Since permissions might be complex to query directly via Eloquent without joining tables,
                // we'll stick to 'admin' role + standard logic. 
                // If you have a PermissionService, you could use that to get user IDs.
                // For now, we assume admins handle this.
            })
            ->pluck('user_id');

        // 3. Send Notifications
        foreach ($recipients as $userId) {
            $this->send(
                $userId,
                'New Join Request',
                "{$requesterName} has requested to join your organization.",
                'org.join_request', // Specific type for filtering
                null, // You could pass the JoinRequest model here if you have one
                "/org/{$orgId}/join-requests", // Action URL to the list
                'normal',
                $orgId
            );
        }
    }

    /**
     * Notify the User about the decision (Approved/Declined)
     */
    public function notifyJoinRequestDecided($joinRequest, $status, $orgName)
    {
        $title = $status === 'approved' ? 'Join Request Approved' : 'Join Request Declined';
        $message = $status === 'approved'
            ? "Your request to join {$orgName} has been accepted!"
            : "Your request to join {$orgName} was declined.";

        $actionUrl = $status === 'approved' ? "/org/{$joinRequest->organization_id}/dashboard" : null;

        $this->send(
            $joinRequest->user_id,
            $title,
            $message,
            'org.join_request_decision',
            null,
            $actionUrl,
            $status === 'approved' ? 'high' : 'normal',
            $joinRequest->organization_id
        );
    }

    /**
     * Mark all "Join Request" notifications as read for a user in a specific org.
     * This handles the "Badge" issue - call this when the admin views the list.
     */
    public function markJoinRequestNotificationsRead($userId, $orgId)
    {
        Notification::where('user_id', $userId)
            ->where('organization_id', $orgId)
            ->where('type', 'org.join_request')
            ->where('is_read', false)
            ->update(['is_read' => true]);
    }
}
