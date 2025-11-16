<?php
// app/Http/Controllers/Duty/DutyTemplateController.php

namespace App\Http\Controllers\Duty;

use App\Http\Controllers\Controller;
use App\Models\DutyTemplate;
use App\Models\Organization;
use Illuminate\Http\Request;

class DutyTemplateController extends Controller
{
    public function index(Organization $organization)
    {
        $this->authorize('manageDutySchedules', $organization);

        $templates = DutyTemplate::where('organization_id', $organization->id)
            ->with('creator:id,name')
            ->latest()
            ->get();

        return response()->json($templates);
    }

    public function store(Request $request, Organization $organization)
    {
        $this->authorize('manageDutySchedules', $organization);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'required_officers' => 'required|integer|min:1',
            'default_days' => 'nullable|array',
            'default_days.*' => 'integer|min:0|max:6',
        ]);

        $template = DutyTemplate::create([
            'organization_id' => $organization->id,
            'created_by' => auth()->id(),
            ...$data,
        ]);

        return response()->json($template, 201);
    }

    public function update(Request $request, Organization $organization, DutyTemplate $dutyTemplate)
    {
        $this->authorize('manageDutySchedules', $organization);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i',
            'required_officers' => 'sometimes|integer|min:1',
            'default_days' => 'nullable|array',
        ]);

        $dutyTemplate->update($data);

        return response()->json($dutyTemplate);
    }

    public function destroy(Organization $organization, DutyTemplate $dutyTemplate)
    {
        $this->authorize('manageDutySchedules', $organization);

        $dutyTemplate->delete();

        return response()->json(['message' => 'Template deleted']);
    }
}
