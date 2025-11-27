<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Get user's notifications
     */
    public function index(Request $request)
    {
        $query = Notification::forUser(auth()->id())
            ->with(['organization:id,name', 'notifiable'])
            ->latest();

        // Filter by read status
        if ($request->has('unread_only') && $request->boolean('unread_only')) {
            $query->unread();
        }

        // Filter by organization
        if ($request->has('organization_id')) {
            $query->forOrganization($request->organization_id);
        }

        // Filter by type
        if ($request->has('type')) {
            $query->byType($request->type);
        }

        // Pagination
        $perPage = $request->input('per_page', 20);
        $notifications = $query->paginate($perPage);

        return response()->json($notifications);
    }

    /**
     * Get unread count
     */
    public function unreadCount(Request $request)
    {
        $count = Notification::forUser(auth()->id())
            ->unread()
            ->count();

        // Get count per organization
        $perOrg = Notification::forUser(auth()->id())
            ->unread()
            ->selectRaw('organization_id, COUNT(*) as count')
            ->groupBy('organization_id')
            ->get()
            ->pluck('count', 'organization_id');

        return response()->json([
            'total' => $count,
            'per_organization' => $perOrg
        ]);
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(Notification $notification)
    {
        // Authorize
        if ($notification->user_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $notification->markAsRead();

        return response()->json($notification);
    }

    /**
     * Mark notification as unread
     */
    public function markAsUnread(Notification $notification)
    {
        // Authorize
        if ($notification->user_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $notification->markAsUnread();

        return response()->json($notification);
    }

    /**
     * Mark all as read
     */
    public function markAllAsRead(Request $request)
    {
        $query = Notification::forUser(auth()->id())->unread();

        // Optionally filter by organization
        if ($request->has('organization_id')) {
            $query->forOrganization($request->organization_id);
        }

        $count = $query->update([
            'is_read' => true,
            'read_at' => now()
        ]);

        return response()->json([
            'message' => 'All notifications marked as read',
            'count' => $count
        ]);
    }

    /**
     * Delete notification
     */
    public function destroy(Notification $notification)
    {
        // Authorize
        if ($notification->user_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $notification->delete();

        return response()->json(['message' => 'Notification deleted']);
    }

    /**
     * Delete all read notifications
     */
    public function deleteAllRead()
    {
        $count = Notification::forUser(auth()->id())
            ->where('is_read', true)
            ->delete();

        return response()->json([
            'message' => 'All read notifications deleted',
            'count' => $count
        ]);
    }

    /**
     * Get notification preferences
     */
    public function getPreferences()
    {
        $user = auth()->user();
        
        // Get from user settings or defaults
        $preferences = $user->notification_preferences ?? $this->getDefaultPreferences();

        return response()->json($preferences);
    }

    /**
     * Update notification preferences
     */
    public function updatePreferences(Request $request)
    {
        $data = $request->validate([
            'duty_assigned' => 'boolean',
            'duty_updated' => 'boolean',
            'duty_cancelled' => 'boolean',
            'duty_reminder' => 'boolean',
            'swap_requested' => 'boolean',
            'swap_accepted' => 'boolean',
            'swap_declined' => 'boolean',
            'assignment_responses' => 'boolean',
        ]);

        $user = auth()->user();
        $user->update(['notification_preferences' => $data]);

        return response()->json([
            'message' => 'Notification preferences updated',
            'preferences' => $data
        ]);
    }

    private function getDefaultPreferences(): array
    {
        return [
            'duty_assigned' => true,
            'duty_updated' => true,
            'duty_cancelled' => true,
            'duty_reminder' => true,
            'swap_requested' => true,
            'swap_accepted' => true,
            'swap_declined' => true,
            'assignment_responses' => true,
        ];
    }
}