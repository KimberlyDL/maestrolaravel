<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AnnouncementController extends Controller
{
    /**
     * Get paginated public announcements feed (infinite scroll)
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function feed(Request $request)
    {
        $perPage = $request->query('per_page', 10);
        $perPage = min(max($perPage, 5), 50); // Between 5 and 50

        $query = DB::table('announcements as a')
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

        // Order by priority first, then by creation date
        $query->orderBy('a.priority', 'desc')
            ->orderBy('a.created_at', 'desc');

        // Paginate results
        $page = $request->query('page', 1);
        $offset = ($page - 1) * $perPage;

        $total = $query->count();
        $announcements = $query
            ->offset($offset)
            ->limit($perPage)
            ->get();

        // Parse tags JSON
        foreach ($announcements as $announcement) {
            $announcement->tags = $announcement->tags ? json_decode($announcement->tags) : [];
        }

        // Calculate pagination metadata
        $lastPage = ceil($total / $perPage);

        return response()->json([
            'data' => $announcements,
            'current_page' => (int) $page,
            'per_page' => (int) $perPage,
            'total' => $total,
            'last_page' => $lastPage,
            'from' => $total > 0 ? $offset + 1 : null,
            'to' => $total > 0 ? min($offset + $perPage, $total) : null,
        ]);
    }

    /**
     * Get all public announcements (legacy endpoint - maintained for compatibility)
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $query = DB::table('announcements as a')
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
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
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
            'image_path' => 'nullable|string|max:500',
        ]);

        $user = Auth::user();

        // Check if user is admin of the organization
        $isAdmin = DB::table('organization_user')
            ->where('organization_id', $data['organization_id'])
            ->where('user_id', $user->id)
            ->where('role', 'admin')
            ->exists();

        if (!$isAdmin) {
            return response()->json([
                'message' => 'Only organization admins can create announcements.'
            ], 403);
        }

        $announcementId = DB::table('announcements')->insertGetId([
            'organization_id' => $data['organization_id'],
            'created_by' => $user->id,
            'title' => $data['title'],
            'content' => $data['content'],
            'is_public' => $data['is_public'] ?? true,
            'priority' => $data['priority'] ?? false,
            'tags' => isset($data['tags']) ? json_encode($data['tags']) : null,
            'image_path' => $data['image_path'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $announcement = DB::table('announcements as a')
            ->leftJoin('organizations as o', 'a.organization_id', '=', 'o.id')
            ->leftJoin('users as u', 'a.created_by', '=', 'u.id')
            ->where('a.id', $announcementId)
            ->select(
                'a.*',
                'o.name as organization_name',
                'o.slug as organization_slug',
                'u.name as author_name'
            )
            ->first();

        // Parse tags
        if ($announcement->tags) {
            $announcement->tags = json_decode($announcement->tags);
        }

        return response()->json([
            'message' => 'Announcement created successfully',
            'announcement' => $announcement
        ], 201);
    }

    /**
     * Update announcement (org admin only)
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $announcement = DB::table('announcements')->where('id', $id)->first();

        if (!$announcement) {
            return response()->json(['message' => 'Announcement not found'], 404);
        }

        $user = Auth::user();

        // Check if user is admin of the organization
        $isAdmin = DB::table('organization_user')
            ->where('organization_id', $announcement->organization_id)
            ->where('user_id', $user->id)
            ->where('role', 'admin')
            ->exists();

        if (!$isAdmin) {
            return response()->json([
                'message' => 'Only organization admins can update announcements.'
            ], 403);
        }

        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'content' => 'sometimes|string|max:5000',
            'is_public' => 'sometimes|boolean',
            'priority' => 'sometimes|boolean',
            'tags' => 'sometimes|array',
            'tags.*' => 'string|max:50',
            'image_path' => 'nullable|string|max:500',
        ]);

        $updateData = [];
        if (isset($data['title'])) $updateData['title'] = $data['title'];
        if (isset($data['content'])) $updateData['content'] = $data['content'];
        if (isset($data['is_public'])) $updateData['is_public'] = $data['is_public'];
        if (isset($data['priority'])) $updateData['priority'] = $data['priority'];
        if (isset($data['tags'])) $updateData['tags'] = json_encode($data['tags']);
        if (array_key_exists('image_path', $data)) $updateData['image_path'] = $data['image_path'];
        $updateData['updated_at'] = now();

        DB::table('announcements')->where('id', $id)->update($updateData);

        $updated = DB::table('announcements as a')
            ->leftJoin('organizations as o', 'a.organization_id', '=', 'o.id')
            ->leftJoin('users as u', 'a.created_by', '=', 'u.id')
            ->where('a.id', $id)
            ->select(
                'a.*',
                'o.name as organization_name',
                'o.slug as organization_slug',
                'u.name as author_name'
            )
            ->first();

        if ($updated->tags) {
            $updated->tags = json_decode($updated->tags);
        }

        return response()->json([
            'message' => 'Announcement updated successfully',
            'announcement' => $updated
        ]);
    }

    /**
     * Delete announcement (org admin only)
     * 
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $announcement = DB::table('announcements')->where('id', $id)->first();

        if (!$announcement) {
            return response()->json(['message' => 'Announcement not found'], 404);
        }

        $user = Auth::user();

        // Check if user is admin of the organization
        $isAdmin = DB::table('organization_user')
            ->where('organization_id', $announcement->organization_id)
            ->where('user_id', $user->id)
            ->where('role', 'admin')
            ->exists();

        if (!$isAdmin) {
            return response()->json([
                'message' => 'Only organization admins can delete announcements.'
            ], 403);
        }

        DB::table('announcements')->where('id', $id)->delete();

        return response()->json([
            'message' => 'Announcement deleted successfully'
        ]);
    }
}
