<?php

namespace App\Http\Controllers\Org;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class OrgManagementController extends Controller
{
    /**
     * Get organization dashboard data
     */
    public function dashboard(Organization $organization)
    {
        $this->authorize('manage', $organization);

        $stats = [
            'total_members' => $organization->members()->count(),
            'pending_requests' => DB::table('organization_join_requests')
                ->where('organization_id', $organization->id)
                ->where('status', 'pending')
                ->count(),
            'total_documents' => $organization->documents()->count(),
            'total_reviews' => $organization->reviewRequests()->count(),
            'announcements' => DB::table('announcements')
                ->where('organization_id', $organization->id)
                ->count(),
        ];

        $recentMembers = $organization->members()
            ->select('users.id', 'users.name', 'users.email', 'users.avatar', 'organization_user.created_at', 'organization_user.role')
            ->orderBy('organization_user.created_at', 'desc')
            ->limit(5)
            ->get();

        $requestsStats = DB::table('organization_join_requests')
            ->where('organization_id', $organization->id)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get()
            ->pluck('count', 'status');

        return response()->json([
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'description' => $organization->description,
                'logo' => $organization->logo,
                'invite_code' => $organization->invite_code,
                'auto_accept_invites' => $organization->auto_accept_invites,
                'public_profile' => $organization->public_profile,
                'member_can_invite' => $organization->member_can_invite,
                'log_retention_days' => $organization->log_retention_days,
                'is_archived' => $organization->is_archived,
            ],
            'stats' => $stats,
            'recent_members' => $recentMembers,
            'requests_stats' => $requestsStats,
        ]);
    }

    /**
     * Get organization overview for editing
     * Changed: Now accessible to all members, not just those with manage permission
     */
    public function overview(Organization $organization)
    {
        // Change from 'manage' to 'viewMembers' - any member can view basic org info
        $this->authorize('viewMembers', $organization);

        // Build location object
        $location = null;
        if ($organization->location_lat && $organization->location_lng) {
            $location = [
                'address' => $organization->location_address,
                'lat' => (float) $organization->location_lat,
                'lng' => (float) $organization->location_lng,
            ];
        }

        return response()->json([
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'description' => $organization->description,
            'mission' => $organization->mission,
            'vision' => $organization->vision,
            'logo' => $organization->logo,
            'website' => $organization->website,
            'auto_accept_invites' => $organization->auto_accept_invites,
            'public_profile' => $organization->public_profile,
            'member_can_invite' => $organization->member_can_invite,
            'log_retention_days' => $organization->log_retention_days,
            'location' => $location,
            'created_at' => $organization->created_at,
            'updated_at' => $organization->updated_at,
        ]);
    }

    /**
     * Update organization overview
     */
    public function updateOverview(Request $request, Organization $organization)
    {
        $this->authorize('manage', $organization);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
            'mission' => 'nullable|string|max:1000',
            'vision' => 'nullable|string|max:1000',
            'website' => 'nullable|url|max:255',
            'location_address' => 'nullable|string|max:500',
            'location_lat' => 'nullable|numeric|between:-90,90',
            'location_lng' => 'nullable|numeric|between:-180,180',
        ]);

        $organization->update($data);

        ActivityLogger::log(
            $organization->id,
            'organization_updated',
            subjectType: 'Organization',
            subjectId: $organization->id,
            description: "{$request->user()->name} updated organization details"
        );

        return response()->json([
            'message' => 'Organization updated successfully',
            'organization' => $organization,
        ]);
    }

    /**
     * Update organization settings
     */
    public function updateSettings(Request $request, Organization $organization)
    {
        $this->authorize('manage', $organization);

        $data = $request->validate([
            'auto_accept_invites' => 'sometimes|boolean',
            'public_profile' => 'sometimes|boolean',
            'member_can_invite' => 'sometimes|boolean',
            'log_retention_days' => 'sometimes|integer|min:1|max:3650',
        ]);

        $organization->update($data);

        ActivityLogger::log(
            $organization->id,
            'settings_updated',
            metadata: $data,
            description: 'Organization settings were updated'
        );

        return response()->json([
            'message' => 'Settings updated successfully',
            'settings' => [
                'auto_accept_invites' => $organization->auto_accept_invites,
                'public_profile' => $organization->public_profile,
                'member_can_invite' => $organization->member_can_invite,
                'log_retention_days' => $organization->log_retention_days,
            ]
        ]);
    }

    /**
     * Upload organization logo
     */
    public function uploadLogo(Request $request, Organization $organization)
    {
        $this->authorize('manage', $organization);

        $request->validate([
            'logo' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        $file = $request->file('logo');

        $imageInfo = getimagesize($file->getRealPath());
        $width = $imageInfo[0];
        $height = $imageInfo[1];

        $ratio = $width / $height;
        if ($ratio < 0.9 || $ratio > 1.1) {
            return response()->json([
                'message' => 'Logo must be square (1:1 aspect ratio). Current ratio: ' . round($ratio, 2),
                'width' => $width,
                'height' => $height,
            ], 422);
        }

        $sourceImage = imagecreatefromstring(file_get_contents($file->getRealPath()));
        $newImage = imagecreatetruecolor(512, 512);

        imagealphablending($newImage, false);
        imagesavealpha($newImage, true);

        imagecopyresampled($newImage, $sourceImage, 0, 0, 0, 0, 512, 512, $width, $height);

        $tempPath = sys_get_temp_dir() . '/' . uniqid('logo_') . '.jpg';
        imagejpeg($newImage, $tempPath, 85);

        imagedestroy($sourceImage);
        imagedestroy($newImage);

        if ($organization->logo) {
            Storage::disk('s3')->delete($organization->logo);
        }

        $filename = 'logos/' . $organization->slug . '_' . time() . '.jpg';

        Storage::disk('s3')->put(
            $filename,
            file_get_contents($tempPath),
            'public'
        );

        unlink($tempPath);

        $organization->update(['logo' => $filename]);

        ActivityLogger::log(
            $organization->id,
            'logo_updated',
            description: 'Organization logo was updated'
        );

        return response()->json([
            'message' => 'Logo uploaded successfully',
            'logo' => $filename,
            'url' => Storage::disk('s3')->url($filename),
        ]);
    }

    /**
     * Delete organization logo
     */
    public function deleteLogo(Organization $organization)
    {
        $this->authorize('manage', $organization);

        if ($organization->logo) {
            Storage::disk('s3')->delete($organization->logo);
        }

        $organization->update(['logo' => null]);

        ActivityLogger::log(
            $organization->id,
            'logo_deleted',
            description: 'Organization logo was removed'
        );

        return response()->json(['message' => 'Logo deleted successfully']);
    }

    /**
     * Get all join requests for organization
     */
    public function joinRequests(Request $request, Organization $organization)
    {
        $this->authorize('manage', $organization);

        $status = $request->query('status', 'pending');

        $requests = DB::table('organization_join_requests as ojr')
            ->join('users as u', 'ojr.user_id', '=', 'u.id')
            ->leftJoin('users as reviewer', 'ojr.reviewed_by', '=', 'reviewer.id')
            ->where('ojr.organization_id', $organization->id)
            ->when($status !== 'all', function ($query) use ($status) {
                return $query->where('ojr.status', $status);
            })
            ->select(
                'ojr.id',
                'ojr.user_id',
                'u.name as user_name',
                'u.email as user_email',
                'u.avatar',
                'ojr.message',
                'ojr.status',
                'ojr.admin_note',
                'ojr.reviewed_by',
                'reviewer.name as reviewed_by_name',
                'ojr.reviewed_at',
                'ojr.created_at',
                'ojr.updated_at'
            )
            ->orderBy('ojr.created_at', 'desc')
            ->get();

        return response()->json($requests);
    }

    /**
     * Approve join request - AUTO-GRANTS DEFAULT PERMISSIONS
     */
    public function approveRequest(Request $request, Organization $organization, $requestId)
    {
        $this->authorize('manage', $organization);

        $data = $request->validate([
            'role' => 'sometimes|in:admin,member,viewer',
            'admin_note' => 'sometimes|string|max:500',
        ]);

        $joinRequest = DB::table('organization_join_requests')
            ->where('id', $requestId)
            ->where('organization_id', $organization->id)
            ->where('status', 'pending')
            ->first();

        if (!$joinRequest) {
            return response()->json(['message' => 'Join request not found or already processed.'], 404);
        }

        $exists = DB::table('organization_user')
            ->where('organization_id', $organization->id)
            ->where('user_id', $joinRequest->user_id)
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'User is already a member.'], 400);
        }

        $role = $data['role'] ?? 'member';

        DB::transaction(function () use ($organization, $joinRequest, $data, $role) {
            // Add user to organization
            DB::table('organization_user')->insert([
                'organization_id' => $organization->id,
                'user_id' => $joinRequest->user_id,
                'role' => $role,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // ✅ AUTO-GRANT DEFAULT PERMISSIONS
            $user = User::find($joinRequest->user_id);
            \App\Services\DefaultPermissions::grantDefaultPermissions($organization, $user, $role);

            // Update join request status
            DB::table('organization_join_requests')
                ->where('id', $joinRequest->id)
                ->update([
                    'status' => 'approved',
                    'admin_note' => $data['admin_note'] ?? 'Your request has been approved.',
                    'reviewed_by' => Auth::id(),
                    'reviewed_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        $user = User::find($joinRequest->user_id);
        ActivityLogger::log(
            $organization->id,
            'member_added',
            subjectType: 'User',
            subjectId: $joinRequest->user_id,
            metadata: ['role' => $role, 'auto_permissions_granted' => true],
            description: "{$user->name} was added as {$role} with default permissions"
        );

        return response()->json([
            'message' => 'Join request approved successfully',
            'user_name' => $user->name,
        ]);
    }

    /**
     * Decline join request
     */
    public function declineRequest(Request $request, Organization $organization, $requestId)
    {
        $this->authorize('manage', $organization);

        $data = $request->validate([
            'admin_note' => 'required|string|max:500',
        ]);

        $joinRequest = DB::table('organization_join_requests')
            ->where('id', $requestId)
            ->where('organization_id', $organization->id)
            ->where('status', 'pending')
            ->first();

        if (!$joinRequest) {
            return response()->json(['message' => 'Join request not found or already processed.'], 404);
        }

        DB::table('organization_join_requests')
            ->where('id', $requestId)
            ->update([
                'status' => 'declined',
                'admin_note' => $data['admin_note'],
                'reviewed_by' => Auth::id(),
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

        ActivityLogger::log(
            $organization->id,
            'join_request_declined',
            subjectType: 'User',
            subjectId: $joinRequest->user_id,
            description: "Join request from {$joinRequest->user_name} was declined"
        );

        return response()->json(['message' => 'Join request declined']);
    }

    /**
     * Get organization members with filters
     */
    public function members(Request $request, Organization $organization)
    {
        $this->authorize('viewMembers', $organization);

        $search = $request->query('search');
        $role = $request->query('role');

        $query = $organization->members()
            ->select('users.id', 'users.name', 'users.email', 'users.avatar', 'users.avatar_url', 'organization_user.role', 'organization_user.created_at as joined_at');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('users.name', 'like', "%{$search}%")
                    ->orWhere('users.email', 'like', "%{$search}%");
            });
        }

        if ($role) {
            $query->where('organization_user.role', $role);
        }

        $members = $query->orderBy('organization_user.created_at', 'desc')->get();

        return response()->json($members->map(function ($member) {
            return [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'avatar' => $member->avatar ?? $member->avatar_url,
                'role' => $member->pivot->role,
                'joined_at' => $member->pivot->created_at,
            ];
        }));
    }


    /**
     * Get basic information for a single member (for read-only profile view)
     */
    public function showMember(Organization $organization, User $user)
    {
        // Ensures the authenticated user is a member of the organization
        $this->authorize('viewMembers', $organization);

        // Ensures the target user is a member of the organization
        if (!$organization->hasMember($user->id)) {
            return response()->json(['message' => 'User is not a member of this organization'], 404);
        }

        // Retrieve the pivot data (role, joined_at)
        $memberPivot = $organization->members()
            ->where('users.id', $user->id)
            ->select('organization_user.role', 'organization_user.created_at')
            ->first();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => $user->avatar,
            'role' => $memberPivot->role,
            'joined_at' => $memberPivot->created_at,
            'active' => true, // Assuming active unless you implement an active status column
            'joinMethod' => 'invited', // Placeholder/default for basic profile
        ]);
    }

    /**
     * Update member role - AUTOMATICALLY ADJUSTS PERMISSIONS
     */
    public function updateMemberRole(Request $request, Organization $organization, User $user)
    {
        $this->authorize('manage', $organization);

        $data = $request->validate([
            'role' => 'required|in:admin,member,viewer',
        ]);

        if (!$organization->hasMember($user->id)) {
            return response()->json(['message' => 'User is not a member of this organization'], 404);
        }

        $oldRole = $organization->getUserRole($user->id);

        // Prevent demoting last admin
        if ($oldRole === 'admin' && $data['role'] !== 'admin') {
            $adminCount = $organization->members()->wherePivot('role', 'admin')->count();
            if ($adminCount <= 1) {
                return response()->json([
                    'message' => 'Cannot demote the last admin of the organization'
                ], 422);
            }
        }

        // Prevent self-demotion
        if ($user->id === Auth::id() && $data['role'] !== 'admin') {
            return response()->json([
                'message' => 'You cannot change your own role'
            ], 422);
        }

        DB::transaction(function () use ($organization, $user, $data, $oldRole) {
            // Update role
            $organization->members()->updateExistingPivot($user->id, [
                'role' => $data['role'],
            ]);

            // ✅ AUTO-UPDATE PERMISSIONS BASED ON NEW ROLE
            \App\Services\DefaultPermissions::updatePermissionsOnRoleChange(
                $organization,
                $user,
                $oldRole,
                $data['role']
            );
        });

        ActivityLogger::log(
            $organization->id,
            'member_role_updated',
            subjectType: 'User',
            subjectId: $user->id,
            metadata: [
                'old_role' => $oldRole,
                'new_role' => $data['role'],
                'permissions_updated' => true
            ],
            description: "{$user->name}'s role was updated from {$oldRole} to {$data['role']}"
        );

        return response()->json([
            'message' => 'Member role updated successfully',
            'member' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $data['role'],
            ],
        ]);
    }

    /**
     * Remove member from organization
     */
    public function removeMember(Request $request, Organization $organization, User $user)
    {
        $this->authorize('manage', $organization);

        if (!$organization->hasMember($user->id)) {
            return response()->json(['message' => 'User is not a member of this organization'], 404);
        }

        if ($user->id === Auth::id()) {
            return response()->json(['message' => 'You cannot remove yourself from the organization'], 422);
        }

        if ($organization->getUserRole($user->id) === 'admin') {
            $adminCount = $organization->members()->wherePivot('role', 'admin')->count();
            if ($adminCount <= 1) {
                return response()->json([
                    'message' => 'Cannot remove the last admin from the organization'
                ], 422);
            }
        }

        $organization->members()->detach($user->id);

        ActivityLogger::log(
            $organization->id,
            'member_removed',
            subjectType: 'User',
            subjectId: $user->id,
            description: "{$user->name} was removed from the organization"
        );

        return response()->json(['message' => 'Member removed successfully']);
    }

    /**
     * Generate or regenerate invite code
     */
    public function generateInviteCode(Organization $organization)
    {
        $this->authorize('manage', $organization);

        $code = strtoupper(Str::random(10));
        $organization->update(['invite_code' => $code]);

        ActivityLogger::log(
            $organization->id,
            'invite_code_generated',
            description: 'New invite code was generated'
        );

        return response()->json([
            'message' => 'Invite code generated successfully',
            'invite_code' => $code,
        ]);
    }

    /**
     * Remove/disable invite code
     */
    public function removeInviteCode(Organization $organization)
    {
        $this->authorize('manage', $organization);

        $organization->update(['invite_code' => null]);

        ActivityLogger::log(
            $organization->id,
            'invite_code_removed',
            description: 'Invite code was removed'
        );

        return response()->json(['message' => 'Invite code removed successfully']);
    }

    /**
     * Get organization announcements
     */
    public function announcements(Request $request, Organization $organization)
    {
        $this->authorize('viewMembers', $organization);

        $announcements = DB::table('announcements as a')
            ->leftJoin('users as u', 'a.created_by', '=', 'u.id')
            ->where('a.organization_id', $organization->id)
            ->select(
                'a.id',
                'a.title',
                'a.content',
                'a.image_path',
                'a.is_public',
                'a.priority',
                'a.tags',
                'a.created_by',
                'u.name as author_name',
                'a.created_at',
                'a.updated_at'
            )
            ->orderBy('a.priority', 'desc')
            ->orderBy('a.created_at', 'desc')
            ->get();

        foreach ($announcements as $announcement) {
            $announcement->tags = $announcement->tags ? json_decode($announcement->tags) : [];
            if ($announcement->image_path) {
                $announcement->image_url = Storage::disk('s3')->url($announcement->image_path);
            }
        }

        return response()->json($announcements);
    }

    /**
     * Create announcement with optional image
     */
    public function createAnnouncement(Request $request, Organization $organization)
    {
        $this->authorize('manage', $organization);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string|max:5000',
            'is_public' => 'boolean',
            'priority' => 'boolean',
            'tags' => 'array',
            'tags.*' => 'string|max:50',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120', // 5MB max
        ]);

        $imagePath = null;

        // Handle image upload
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $filename = 'announcements/' . $organization->id . '/' . uniqid() . '.' . $file->extension();

            Storage::disk('s3')->put(
                $filename,
                file_get_contents($file->getRealPath()),
                'public'
            );

            $imagePath = $filename;
        }

        $announcementId = DB::table('announcements')->insertGetId([
            'organization_id' => $organization->id,
            'created_by' => Auth::id(),
            'title' => $data['title'],
            'content' => $data['content'],
            'image_path' => $imagePath,
            'is_public' => $data['is_public'] ?? true,
            'priority' => $data['priority'] ?? false,
            'tags' => isset($data['tags']) ? json_encode($data['tags']) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $announcement = DB::table('announcements')->where('id', $announcementId)->first();
        $announcement->tags = $announcement->tags ? json_decode($announcement->tags) : [];
        if ($announcement->image_path) {
            $announcement->image_url = Storage::disk('s3')->url($announcement->image_path);
        }

        ActivityLogger::log(
            $organization->id,
            'announcement_created',
            subjectType: 'Announcement',
            subjectId: $announcementId,
            description: "Announcement '{$data['title']}' was created"
        );

        return response()->json([
            'message' => 'Announcement created successfully',
            'announcement' => $announcement
        ], 201);
    }

    /**
     * Update announcement
     */
    public function updateAnnouncement(Request $request, Organization $organization, $announcementId)
    {
        $this->authorize('manage', $organization);

        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'content' => 'sometimes|string|max:5000',
            'is_public' => 'sometimes|boolean',
            'priority' => 'sometimes|boolean',
            'tags' => 'sometimes|array',
            'tags.*' => 'string|max:50',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120',
            'remove_image' => 'sometimes|boolean',
        ]);

        $announcement = DB::table('announcements')
            ->where('id', $announcementId)
            ->where('organization_id', $organization->id)
            ->first();

        if (!$announcement) {
            return response()->json(['message' => 'Announcement not found'], 404);
        }

        $updateData = [];
        if (isset($data['title'])) $updateData['title'] = $data['title'];
        if (isset($data['content'])) $updateData['content'] = $data['content'];
        if (isset($data['is_public'])) $updateData['is_public'] = $data['is_public'];
        if (isset($data['priority'])) $updateData['priority'] = $data['priority'];
        if (isset($data['tags'])) $updateData['tags'] = json_encode($data['tags']);

        // Handle image removal
        if (isset($data['remove_image']) && $data['remove_image'] && $announcement->image_path) {
            Storage::disk('s3')->delete($announcement->image_path);
            $updateData['image_path'] = null;
        }

        // Handle new image upload
        if ($request->hasFile('image')) {
            // Delete old image if exists
            if ($announcement->image_path) {
                Storage::disk('s3')->delete($announcement->image_path);
            }

            $file = $request->file('image');
            $filename = 'announcements/' . $organization->id . '/' . uniqid() . '.' . $file->extension();

            Storage::disk('s3')->put(
                $filename,
                file_get_contents($file->getRealPath()),
                'public'
            );

            $updateData['image_path'] = $filename;
        }

        $updateData['updated_at'] = now();

        DB::table('announcements')->where('id', $announcementId)->update($updateData);

        $updated = DB::table('announcements')->where('id', $announcementId)->first();
        $updated->tags = $updated->tags ? json_decode($updated->tags) : [];
        if ($updated->image_path) {
            $updated->image_url = Storage::disk('s3')->url($updated->image_path);
        }

        ActivityLogger::log(
            $organization->id,
            'announcement_updated',
            subjectType: 'Announcement',
            subjectId: $announcementId,
            description: "Announcement '{$updated->title}' was updated"
        );

        return response()->json([
            'message' => 'Announcement updated successfully',
            'announcement' => $updated
        ]);
    }

    /**
     * Delete announcement
     */
    public function deleteAnnouncement(Organization $organization, $announcementId)
    {
        $this->authorize('manage', $organization);

        $announcement = DB::table('announcements')
            ->where('id', $announcementId)
            ->where('organization_id', $organization->id)
            ->first();

        if (!$announcement) {
            return response()->json(['message' => 'Announcement not found'], 404);
        }

        // Delete image if exists
        if ($announcement->image_path) {
            Storage::disk('s3')->delete($announcement->image_path);
        }

        DB::table('announcements')->where('id', $announcementId)->delete();

        ActivityLogger::log(
            $organization->id,
            'announcement_deleted',
            subjectType: 'Announcement',
            subjectId: $announcementId,
            description: "Announcement '{$announcement->title}' was deleted"
        );

        return response()->json(['message' => 'Announcement deleted successfully']);
    }

    /**
     * Get organization statistics
     */
    public function statistics(Organization $organization)
    {
        $this->authorize('manage', $organization);

        $membersByRole = DB::table('organization_user')
            ->where('organization_id', $organization->id)
            ->select('role', DB::raw('count(*) as count'))
            ->groupBy('role')
            ->get()
            ->pluck('count', 'role');

        $requestsByStatus = DB::table('organization_join_requests')
            ->where('organization_id', $organization->id)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get()
            ->pluck('count', 'status');

        $memberGrowth = DB::table('organization_user')
            ->where('organization_id', $organization->id)
            ->where('created_at', '>=', now()->subMonths(6))
            ->select(
                DB::raw('DATE_FORMAT(created_at, "%Y-%m") as month'),
                DB::raw('count(*) as count')
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return response()->json([
            'members_by_role' => $membersByRole,
            'requests_by_status' => $requestsByStatus,
            'member_growth' => $memberGrowth,
            'total_members' => $organization->members()->count(),
            'total_documents' => $organization->documents()->count(),
            'total_reviews' => $organization->reviewRequests()->count(),
        ]);
    }

    /**
     * Leave organization (for members)
     */
    public function leave(Request $request, Organization $organization)
    {
        $user = Auth::user();

        if (!$organization->hasMember($user->id)) {
            return response()->json(['message' => 'You are not a member of this organization'], 404);
        }

        $userRole = $organization->getUserRole($user->id);

        if ($userRole === 'admin') {
            $adminCount = $organization->members()->wherePivot('role', 'admin')->count();

            if ($adminCount <= 1) {
                // Check if a new admin is specified
                $data = $request->validate([
                    'new_admin_id' => 'required|exists:users,id',
                ]);

                // Verify new admin is a member
                if (!$organization->hasMember($data['new_admin_id'])) {
                    return response()->json([
                        'message' => 'The specified user is not a member of this organization'
                    ], 422);
                }

                // Promote new admin
                $organization->members()->updateExistingPivot($data['new_admin_id'], [
                    'role' => 'admin',
                ]);

                $newAdmin = User::find($data['new_admin_id']);

                ActivityLogger::log(
                    $organization->id,
                    'ownership_transferred',
                    subjectType: 'User',
                    subjectId: $data['new_admin_id'],
                    metadata: ['from_user_id' => $user->id],
                    description: "Admin privileges transferred from {$user->name} to {$newAdmin->name}"
                );
            }
        }

        $organization->members()->detach($user->id);

        ActivityLogger::log(
            $organization->id,
            'member_left',
            subjectType: 'User',
            subjectId: $user->id,
            description: "{$user->name} left the organization"
        );

        return response()->json(['message' => 'You have left the organization successfully']);
    }

    /**
     * Archive organization
     */
    public function archiveOrganization(Organization $organization)
    {
        $this->authorize('manage', $organization);

        $organization->update([
            'is_archived' => true,
            'archived_at' => now(),
        ]);

        ActivityLogger::log(
            $organization->id,
            'organization_archived',
            description: 'Organization was archived'
        );

        return response()->json(['message' => 'Organization archived successfully']);
    }

    /**
     * Restore archived organization
     */
    public function restoreOrganization(Organization $organization)
    {
        $this->authorize('manage', $organization);

        $organization->update([
            'is_archived' => false,
            'archived_at' => null,
        ]);

        ActivityLogger::log(
            $organization->id,
            'organization_restored',
            description: 'Organization was restored from archive'
        );

        return response()->json(['message' => 'Organization restored successfully']);
    }

    /**
     * Export organization data
     */
    public function exportData(Organization $organization)
    {
        $this->authorize('manage', $organization);

        $data = [
            'organization' => [
                'name' => $organization->name,
                'slug' => $organization->slug,
                'description' => $organization->description,
                'created_at' => $organization->created_at,
            ],
            'members' => $organization->members()->get()->map(function ($member) {
                return [
                    'name' => $member->name,
                    'email' => $member->email,
                    'role' => $member->pivot->role,
                    'joined_at' => $member->pivot->created_at,
                ];
            }),
            'statistics' => [
                'total_members' => $organization->members()->count(),
                'total_documents' => $organization->documents()->count(),
                'total_reviews' => $organization->reviewRequests()->count(),
                'total_announcements' => DB::table('announcements')
                    ->where('organization_id', $organization->id)
                    ->count(),
            ],
            'exported_at' => now()->toISOString(),
        ];

        ActivityLogger::log(
            $organization->id,
            'data_exported',
            description: 'Organization data was exported'
        );

        return response()->json([
            'message' => 'Data exported successfully',
            'data' => $data
        ]);
    }

    /**
     * Initiate ownership transfer
     */
    public function initiateOwnershipTransfer(Request $request, Organization $organization)
    {
        $this->authorize('manage', $organization);

        $data = $request->validate([
            'to_user_id' => 'required|exists:users,id',
            'message' => 'nullable|string|max:1000',
        ]);

        $currentUser = Auth::user();

        // Verify current user is admin
        if ($organization->getUserRole($currentUser->id) !== 'admin') {
            return response()->json(['message' => 'Only admins can transfer ownership'], 403);
        }

        // Verify target user is a member
        if (!$organization->hasMember($data['to_user_id'])) {
            return response()->json(['message' => 'Target user must be a member of the organization'], 422);
        }

        // Check for existing pending transfer
        $existingTransfer = DB::table('organization_ownership_transfers')
            ->where('organization_id', $organization->id)
            ->where('status', 'pending')
            ->first();

        if ($existingTransfer) {
            return response()->json(['message' => 'There is already a pending ownership transfer'], 422);
        }

        $transferId = DB::table('organization_ownership_transfers')->insertGetId([
            'organization_id' => $organization->id,
            'from_user_id' => $currentUser->id,
            'to_user_id' => $data['to_user_id'],
            'status' => 'pending',
            'message' => $data['message'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $targetUser = User::find($data['to_user_id']);

        ActivityLogger::log(
            $organization->id,
            'ownership_transfer_initiated',
            metadata: [
                'from_user_id' => $currentUser->id,
                'to_user_id' => $data['to_user_id'],
                'transfer_id' => $transferId,
            ],
            description: "{$currentUser->name} initiated ownership transfer to {$targetUser->name}"
        );

        return response()->json([
            'message' => 'Ownership transfer request sent',
            'transfer_id' => $transferId
        ]);
    }

    /**
     * Accept ownership transfer
     */
    public function acceptOwnershipTransfer(Organization $organization, $transferId)
    {
        $user = Auth::user();

        $transfer = DB::table('organization_ownership_transfers')
            ->where('id', $transferId)
            ->where('organization_id', $organization->id)
            ->where('to_user_id', $user->id)
            ->where('status', 'pending')
            ->first();

        if (!$transfer) {
            return response()->json(['message' => 'Transfer request not found or already processed'], 404);
        }

        DB::transaction(function () use ($organization, $transfer, $user) {
            // Promote new owner to admin
            $organization->members()->updateExistingPivot($user->id, [
                'role' => 'admin',
            ]);

            // Update transfer status
            DB::table('organization_ownership_transfers')
                ->where('id', $transfer->id)
                ->update([
                    'status' => 'accepted',
                    'accepted_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        $fromUser = User::find($transfer->from_user_id);

        ActivityLogger::log(
            $organization->id,
            'ownership_transfer_completed',
            metadata: [
                'from_user_id' => $transfer->from_user_id,
                'to_user_id' => $user->id,
                'transfer_id' => $transfer->id,
            ],
            description: "Ownership transfer from {$fromUser->name} to {$user->name} completed"
        );

        return response()->json(['message' => 'You are now an admin of this organization']);
    }

    /**
     * Decline ownership transfer
     */
    public function declineOwnershipTransfer(Organization $organization, $transferId)
    {
        $user = Auth::user();

        $transfer = DB::table('organization_ownership_transfers')
            ->where('id', $transferId)
            ->where('organization_id', $organization->id)
            ->where('to_user_id', $user->id)
            ->where('status', 'pending')
            ->first();

        if (!$transfer) {
            return response()->json(['message' => 'Transfer request not found or already processed'], 404);
        }

        DB::table('organization_ownership_transfers')
            ->where('id', $transferId)
            ->update([
                'status' => 'declined',
                'declined_at' => now(),
                'updated_at' => now(),
            ]);

        ActivityLogger::log(
            $organization->id,
            'ownership_transfer_declined',
            metadata: [
                'from_user_id' => $transfer->from_user_id,
                'to_user_id' => $user->id,
                'transfer_id' => $transferId,
            ],
            description: "{$user->name} declined ownership transfer"
        );

        return response()->json(['message' => 'Ownership transfer declined']);
    }

    /**
     * Get activity log
     */
    public function activityLog(Request $request, Organization $organization)
    {
        $this->authorize('manage', $organization);

        $limit = $request->query('limit', 50);
        $activities = ActivityLogger::getRecent($organization->id, $limit);

        return response()->json($activities);
    }
}
