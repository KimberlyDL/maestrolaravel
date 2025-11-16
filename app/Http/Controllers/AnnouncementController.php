<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AnnouncementController extends Controller
{
    /**
     * Get all public announcements
     */
    public function index(Request $request)
    {
        $query = \DB::table('announcements as a')
            ->join('organizations as o', 'a.organization_id', '=', 'o.id')
            ->leftJoin('users as u', 'a.created_by', '=', 'u.id')
            ->where('a.is_public', true)
            ->select(
                'a.id',
                'a.organization_id',
                'o.name as organization_name',
                'o.slug as organization_slug',
                'a.title',
                'a.content',
                'a.image_path',
                'a.priority',
                'a.tags',
                'a.created_by',
                'u.name as author_name',
                'a.created_at',
                'a.updated_at'
            );

        // Filter by organization if specified
        if ($orgId = $request->query('organization_id')) {
            $query->where('a.organization_id', $orgId);
        }

        $announcements = $query
            ->orderBy('a.priority', 'desc')
            ->orderBy('a.created_at', 'desc')
            ->limit(50)
            ->get();

        // Parse tags JSON
        foreach ($announcements as $announcement) {
            $announcement->tags = $announcement->tags ? json_decode($announcement->tags) : [];
        }

        return response()->json($announcements);
    }

    /**
     * Create announcement (org admin only)
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'organization_id' => 'required|exists:organizations,id',
            'title' => 'required|string|max:255',
            'content' => 'required|string|max:5000',
            'is_public' => 'boolean',
            'priority' => 'boolean',
            'tags' => 'array',
            'tags.*' => 'string|max:50',
        ]);

        $user = Auth::user();

        // Check if user is admin of the organization
        $isAdmin = \DB::table('organization_user')
            ->where('organization_id', $data['organization_id'])
            ->where('user_id', $user->id)
            ->where('role', 'admin')
            ->exists();

        if (!$isAdmin) {
            return response()->json(['message' => 'Only organization admins can create announcements.'], 403);
        }

        $announcementId = \DB::table('announcements')->insertGetId([
            'organization_id' => $data['organization_id'],
            'created_by' => $user->id,
            'title' => $data['title'],
            'content' => $data['content'],
            'is_public' => $data['is_public'] ?? true,
            'priority' => $data['priority'] ?? false,
            'tags' => isset($data['tags']) ? json_encode($data['tags']) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $announcement = \DB::table('announcements')->where('id', $announcementId)->first();

        return response()->json([
            'message' => 'Announcement created successfully',
            'announcement' => $announcement
        ], 201);
    }
}
