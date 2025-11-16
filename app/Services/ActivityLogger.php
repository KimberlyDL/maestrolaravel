<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

class ActivityLogger
{
    /**
     * Log an activity for an organization
     */
    public static function log(
        int $organizationId,
        string $action,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?array $metadata = null,
        ?string $description = null
    ): ActivityLog {
        return ActivityLog::create([
            'organization_id' => $organizationId,
            'user_id' => Auth::id(),
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'metadata' => $metadata,
            'description' => $description,
        ]);
    }

    /**
     * Get recent activity logs for an organization
     */
    public static function getRecent(int $organizationId, int $limit = 50)
    {
        return ActivityLog::where('organization_id', $organizationId)
            ->with('user:id,name,avatar,avatar_url')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get activity logs with filters
     */
    public static function query(int $organizationId)
    {
        return ActivityLog::where('organization_id', $organizationId)
            ->with('user:id,name,avatar,avatar_url');
    }

    /**
     * Get activity logs for a specific subject
     */
    public static function getForSubject(int $organizationId, string $subjectType, int $subjectId)
    {
        return ActivityLog::where('organization_id', $organizationId)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->with('user:id,name,avatar,avatar_url')
            ->orderBy('created_at', 'desc')
            ->get();
    }
}
