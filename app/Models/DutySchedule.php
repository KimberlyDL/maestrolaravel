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

        'check_in_window_start',
        'check_in_window_end',
        'check_out_window_start',
        'check_out_window_end',
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
    // public function getAssignedCountAttribute(): int
    // {
    //     return $this->assignments()
    //         ->whereIn('status', ['assigned', 'confirmed', 'complete', 'no-show'])
    //         ->count();
    // }


    public function getAssignedCountAttribute(): int
    {
        return $this->assignments()
            ->whereIn('status', ['assigned', 'confirmed', 'completed', 'no_show']) // UPDATED
            ->count();
    }

    /**
     * FIX 2: Format window times to H:i for frontend display, assuming DB stores H:i:s
     */
    public function getCheckInWindowStartAttribute(?string $value): ?string
    {
        return $value ? substr($value, 0, 5) : null;
    }

    public function getCheckInWindowEndAttribute(?string $value): ?string
    {
        return $value ? substr($value, 0, 5) : null;
    }

    public function getCheckOutWindowStartAttribute(?string $value): ?string
    {
        return $value ? substr($value, 0, 5) : null;
    }

    public function getCheckOutWindowEndAttribute(?string $value): ?string
    {
        return $value ? substr($value, 0, 5) : null;
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
