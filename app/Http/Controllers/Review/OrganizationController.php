<?php

namespace App\Http\Controllers\Review;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use App\Models\OrganizationUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use App\Services\ActivityLogger;

class OrganizationController extends Controller
{

    public function show(Organization $organization)
    {
        $user = Auth::user();

        // Determine user's relationship to this org
        $userStatus = 'none';
        $userRole = null;

        if ($user) {
            $membership = $organization->memberships()->where('user_id', $user->id)->first();
            if ($membership) {
                $userStatus = 'member';
                $userRole = $membership->role;
                if ($userRole === 'admin') {
                    $userStatus = 'admin';
                }
            } else {
                // Check if there's a pending request
                $pendingRequest = \DB::table('organization_join_requests')
                    ->where('organization_id', $organization->id)
                    ->where('user_id', $user->id)
                    ->where('status', 'pending')
                    ->exists();

                if ($pendingRequest) {
                    $userStatus = 'pending';
                }
            }
        }

        // Get member count
        $memberCount = $organization->members()->count();

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
            'logo_url' => $organization->logo_url,
            'website' => $organization->website,
            'members' => $memberCount,
            'user_status' => $userStatus,
            'user_role' => $userRole,
            'location' => $location,
        ]);
    }

    
    // /**
    //  * Get the current user's pending join requests
    //  */
    // public function myRequests(Request $request)
    // {
    //     $user = $request->user();

    //     $requests = \DB::table('organization_join_requests')
    //         ->where('user_id', $user->id)
    //         ->where('status', 'pending')
    //         ->get();

    //     return response()->json($requests);
    // }

    public function myRequests(Request $request)
    {
        $user = $request->user();

        $requests = \DB::table('organization_join_requests as r')
            ->join('organizations as o', 'o.id', '=', 'r.organization_id')
            ->where('r.user_id', $user->id)
            ->select(
                'r.*',
                'o.name as organization_name',
                'o.slug as organization_slug'
            )
            ->orderBy('r.created_at', 'desc')
            ->get();

        return response()->json($requests);
    }

    /**
     * Cancel a pending join request
     */
    public function cancelRequest(Request $request, $requestId)
    {
        $user = $request->user();

        $joinRequest = \DB::table('organization_join_requests')
            ->where('id', $requestId)
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->first();

        if (!$joinRequest) {
            return response()->json(['message' => 'Join request not found or already processed.'], 404);
        }

        \DB::table('organization_join_requests')
            ->where('id', $requestId)
            ->update([
                'status' => 'cancelled',
                'updated_at' => now(),
            ]);

        ActivityLogger::log(
            $joinRequest->organization_id,
            'join_request_cancelled',
            subjectType: 'User',
            subjectId: $user->id,
            description: "{$user->name} cancelled their join request"
        );

        return response()->json(['message' => 'Join request cancelled successfully.']);
    }

    /**
     * Get all members of an organization
     */
    public function members(Organization $organization)
    {
        // Check if user has access to view this organization's members
        $this->authorize('viewMembers', $organization);

        // Get all members with their user data and pivot role
        $members = $organization->members()
            ->select('users.id', 'users.name', 'users.email', 'users.avatar', 'users.avatar_url')
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar ?? $user->avatar_url,
                    'role' => $user->pivot->role,
                ];
            });

        return response()->json($members);
    }

    // /**
    //  * Get user's organizations
    //  */
    // public function index(Request $request)
    // {
    //     $user = $request->user();

    //     $organizations = $user->organizations()
    //         ->select('organizations.id', 'organizations.name', 'organizations.slug', 'organizations.description')
    //         ->get()
    //         ->map(function ($org) {
    //             return [
    //                 'id' => $org->id,
    //                 'name' => $org->name,
    //                 'slug' => $org->slug,
    //                 'description' => $org->description,
    //                 'role' => $org->pivot->role,
    //             ];
    //         });

    //     return response()->json($organizations);
    // }

    // /**
    //  * Get user's organizations (via pivot)
    //  */
    // public function index(Request $request)
    // {
    //     $user = $request->user();

    //     $memberships = OrganizationUser::query()
    //         ->where('user_id', $user->id)
    //         ->with(['organization:id,name,slug,description']) // eager-load org
    //         ->get();

    //     $organizations = $memberships
    //         ->filter(fn($m) => $m->organization) // guard against nulls
    //         ->map(fn($m) => [
    //             'id'          => $m->organization->id,
    //             'name'        => $m->organization->name,
    //             'slug'        => $m->organization->slug,
    //             'description' => $m->organization->description,
    //             'role'        => $m->role, // from pivot
    //         ])
    //         ->values();

    //     return response()->json($organizations);
    // }



    /**
     * GET /api/organizations?scope=mine|others
     * - mine   : orgs where current user is a member (with role)
     * - others : orgs where current user is NOT a member (no role)
     * default  : mine
     */
    public function index(Request $request)
    {
        $user  = $request->user();
        $scope = $request->query('scope', 'mine');

        if ($scope === 'mine') {
            // via pivot (role included)
            $memberships = OrganizationUser::query()
                ->where('user_id', $user->id)
                ->with(['organization:id,name,slug,logo,description'])
                ->get();

            $organizations = $memberships
                ->filter(fn($m) => $m->organization)
                ->map(fn($m) => [
                    'id'          => $m->organization->id,
                    'name'        => $m->organization->name,
                    'slug'        => $m->organization->slug,
                    'logo'        => $m->organization->logo,
                    'description' => $m->organization->description,
                    'role'        => $m->role, // from pivot
                ])
                ->values();

            return response()->json($organizations);
        }

        if ($scope === 'others') {
            // collect my org ids
            $myOrgIds = OrganizationUser::query()
                ->where('user_id', $user->id)
                ->pluck('organization_id');

            // list orgs I am NOT a member of
            $others = Organization::query()
                ->whereNotIn('id', $myOrgIds)
                ->select('id', 'name', 'slug', 'logo', 'description')
                ->orderBy('name')
                ->get()
                ->map(fn($o) => [
                    'id'          => $o->id,
                    'name'        => $o->name,
                    'slug'        => $o->slug,
                    'logo'        => $o->logo,
                    'description' => $o->description,
                    // no role (I’m not a member)
                ])
                ->values();

            return response()->json($others);
        }

        // default fallback
        return $this->index(new Request(['scope' => 'mine']));
    }

    /**
     * Create a new organization
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'slug' => 'nullable|string|max:255|unique:organizations,slug',
        ]);

        // Generate slug if not provided
        if (empty($data['slug'])) {
            $baseSlug = Str::slug($data['name']);
            $slug = $baseSlug;
            $i = 1;

            while (Organization::where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $i++;
            }

            $data['slug'] = $slug;
        }

        // Create organization
        $organization = Organization::create([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
        ]);

        // Add creator as admin
        $request->user()->organizations()->attach($organization->id, [
            'role' => 'admin',
        ]);

        return response()->json([
            'message' => 'Organization created successfully',
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'description' => $organization->description,
                'role' => 'admin',
            ],
        ], 201);
    }

    /**
     * Add a member to an organization
     */
    public function addMember(Request $request, Organization $organization)
    {
        $this->authorize('manage', $organization);

        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'role' => 'required|in:admin,member,viewer',
        ]);

        // Check if user is already a member
        if ($organization->hasMember($data['user_id'])) {
            return response()->json([
                'message' => 'User is already a member of this organization'
            ], 422);
        }

        // Add member
        $organization->members()->attach($data['user_id'], [
            'role' => $data['role'],
        ]);

        $user = User::find($data['user_id']);

        return response()->json([
            'message' => 'Member added successfully',
            'member' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $data['role'],
            ],
        ]);
    }

    /**
     * Remove a member from an organization
     */
    public function removeMember(Request $request, Organization $organization, User $user)
    {
        $this->authorize('manage', $organization);

        // Prevent removing the last admin
        if ($organization->getUserRole($user->id) === 'admin') {
            $adminCount = $organization->members()->wherePivot('role', 'admin')->count();
            if ($adminCount <= 1) {
                return response()->json([
                    'message' => 'Cannot remove the last admin from the organization'
                ], 422);
            }
        }

        $organization->members()->detach($user->id);

        return response()->json([
            'message' => 'Member removed successfully'
        ]);
    }

    /**
     * Update member role
     */
    public function updateMemberRole(Request $request, Organization $organization, User $user)
    {
        $this->authorize('manage', $organization);

        $data = $request->validate([
            'role' => 'required|in:admin,member,viewer',
        ]);

        // Check if user is a member
        if (!$organization->hasMember($user->id)) {
            return response()->json([
                'message' => 'User is not a member of this organization'
            ], 404);
        }

        // Prevent demoting the last admin
        if ($organization->getUserRole($user->id) === 'admin' && $data['role'] !== 'admin') {
            $adminCount = $organization->members()->wherePivot('role', 'admin')->count();
            if ($adminCount <= 1) {
                return response()->json([
                    'message' => 'Cannot demote the last admin of the organization'
                ], 422);
            }
        }

        // Update role
        $organization->members()->updateExistingPivot($user->id, [
            'role' => $data['role'],
        ]);

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
     * User submits join request (by org name)
     */
    public function joinRequest(Request $request)
    {
        $request->validate([
            'organization_name' => 'required|string|max:255',
        ]);

        $user = Auth::user();

        $organization = Organization::where('name', 'LIKE', $request->organization_name)->first();
        if (!$organization) {
            return response()->json(['message' => 'Organization not found.'], 404);
        }

        $isMember = OrganizationUser::where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->exists();

        if ($isMember) {
            return response()->json(['message' => 'You are already a member of this organization.'], 400);
        }

        // Create a join request that needs admin approval
        $existingRequest = \DB::table('organization_join_requests')
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->exists();

        if ($existingRequest) {
            return response()->json([
                'message' => 'You already have a pending join request for this organization.',
                'organization' => $organization->name,
            ], 400);
        }

        \DB::table('organization_join_requests')->insert([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'message' => 'Joined via request',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ActivityLogger::log(
            $organization->id,
            'join_request_via_request_form',
            subjectType: 'User',
            subjectId: $user->id,
            metadata: ['auto_accepted' => false],
            description: "{$user->name} requested to join via form"
        );

        return response()->json([
            'message' => 'Join request sent successfully. An admin will review your request.',
            'organization' => $organization->name,
            'auto_accepted' => false,
        ], 200);
    }

    /**
     * User joins directly via invite code
     */
    public function joinViaInvite(Request $request)
    {
        $request->validate([
            'code' => 'required|string|max:255',
        ]);

        $user = Auth::user();

        $organization = Organization::where('invite_code', $request->code)->first();
        if (!$organization) {
            return response()->json(['message' => 'Invalid or expired invite code.'], 404);
        }

        $exists = OrganizationUser::where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'You are already part of this organization.'], 400);
        }

        // Check if auto-accept is enabled
        if ($organization->auto_accept_invites) {
            // Automatically add user as member
            OrganizationUser::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'role' => 'member',
            ]);

            ActivityLogger::log(
                $organization->id,
                'member_joined_via_invite',
                subjectType: 'User',
                subjectId: $user->id,
                metadata: ['auto_accepted' => true],
                description: "{$user->name} joined via invite code (auto-accepted)"
            );

            return response()->json([
                'message' => 'Successfully joined the organization!',
                'organization' => $organization->name,
                'auto_accepted' => true,
            ], 200);
        } else {
            // Create a join request that needs admin approval
            $existingRequest = \DB::table('organization_join_requests')
                ->where('organization_id', $organization->id)
                ->where('user_id', $user->id)
                ->where('status', 'pending')
                ->exists();

            if ($existingRequest) {
                return response()->json([
                    'message' => 'You already have a pending join request for this organization.',
                    'organization' => $organization->name,
                ], 400);
            }

            \DB::table('organization_join_requests')->insert([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'message' => 'Joined via invite code: ' . $request->code,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            ActivityLogger::log(
                $organization->id,
                'join_request_via_invite',
                subjectType: 'User',
                subjectId: $user->id,
                metadata: ['auto_accepted' => false],
                description: "{$user->name} requested to join via invite code"
            );

            return response()->json([
                'message' => 'Join request sent successfully. An admin will review your request.',
                'organization' => $organization->name,
                'auto_accepted' => false,
            ], 200);
        }
    }

    /**
     * Admin generates a new invite code (replaces old)
     */
    public function generateInviteCode(Organization $organization)
    {
        $user = Auth::user();

        // Optional: Check if user is admin of this organization
        $isAdmin = OrganizationUser::where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['admin', 'owner'])
            ->exists();

        if (!$isAdmin) {
            return response()->json(['message' => 'You are not authorized to manage this organization.'], 403);
        }

        $organization->invite_code = strtoupper(Str::random(10));
        $organization->save();

        return response()->json([
            'message' => 'Invite code generated successfully.',
            'invite_code' => $organization->invite_code,
        ], 200);
    }

    /**
     * Admin removes (disables) the invite code
     */
    public function removeInviteCode(Organization $organization)
    {
        $user = Auth::user();

        $isAdmin = OrganizationUser::where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['admin', 'owner'])
            ->exists();

        if (!$isAdmin) {
            return response()->json(['message' => 'You are not authorized to manage this organization.'], 403);
        }

        $organization->invite_code = null;
        $organization->save();

        return response()->json(['message' => 'Invite code has been removed.'], 200);
    }
}
