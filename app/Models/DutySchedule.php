<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DutySchedule extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'title',
        'description',
        'date',
        'start_time',
        'end_time',
        'location',
        'required_officers',
        'status',
        'recurrence_type',
        'recurrence_days',
        'recurrence_end_date',
        'created_by',
    ];

    protected $casts = [
        'date' => 'date',
        'recurrence_days' => 'array',
        'recurrence_end_date' => 'date',
    ];

    protected $appends = ['assigned_count'];

    // ===== Relationships =====

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(DutyAssignment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ===== Accessors =====

    /**
     * Get count of active assignments (assigned or confirmed)
     */
    public function getAssignedCountAttribute(): int
    {
        return $this->assignments()
            ->whereIn('status', ['assigned', 'confirmed'])
            ->count();
    }

    // ===== Scopes =====

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeInDateRange($query, string $startDate, string $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeUpcoming($query)
    {
        return $query->where('date', '>=', now()->toDateString());
    }

    // ===== Helper Methods =====

    /**
     * Check if schedule needs more officers
     */
    public function needsOfficers(): bool
    {
        return $this->assigned_count < $this->required_officers;
    }

    /**
     * Check if schedule is fully staffed
     */
    public function isFullyStaffed(): bool
    {
        return $this->assigned_count >= $this->required_officers;
    }

    /**
     * Get remaining officer slots
     */
    public function getRemainingSlots(): int
    {
        return max(0, $this->required_officers - $this->assigned_count);
    }
}
