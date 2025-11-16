<?php

namespace App\Http\Controllers\Duty;

use App\Http\Controllers\Controller;
use App\Models\DutyAvailability;
use App\Models\Organization;
use Illuminate\Http\Request;

class DutyAvailabilityController extends Controller
{
    /**
     * Get officer availability
     */
    public function index(Request $request, Organization $organization)
    {
        $this->authorize('viewDutySchedules', $organization);

        $query = DutyAvailability::where('organization_id', $organization->id)
            ->with('user:id,name,email,avatar,avatar_url');

        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('date', [$request->start_date, $request->end_date]);
        }

        $availability = $query->orderBy('date')->orderBy('start_time')->get();

        return response()->json($availability);
    }

    /**
     * Set availability
     */
    public function store(Request $request, Organization $organization)
    {
        $data = $request->validate([
            'date' => 'required|date|after_or_equal:today',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'availability_type' => 'required|in:available,unavailable,preferred',
            'reason' => 'nullable|string',
        ]);

        $availability = DutyAvailability::create([
            'organization_id' => $organization->id,
            'user_id' => auth()->id(),
            ...$data,
        ]);

        return response()->json($availability, 201);
    }

    /**
     * Update availability
     */
    public function update(Request $request, Organization $organization, DutyAvailability $dutyAvailability)
    {
        if ($dutyAvailability->user_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i',
            'availability_type' => 'sometimes|in:available,unavailable,preferred',
            'reason' => 'nullable|string',
        ]);

        $dutyAvailability->update($data);

        return response()->json($dutyAvailability);
    }

    /**
     * Delete availability
     */
    public function destroy(Organization $organization, DutyAvailability $dutyAvailability)
    {
        if ($dutyAvailability->user_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $dutyAvailability->delete();

        return response()->json(['message' => 'Availability deleted']);
    }
}
