<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DutySwapRequest extends Model
{
    protected $fillable = [
        'duty_assignment_id',
        'from_officer_id',
        'to_officer_id',
        'reason',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function dutyAssignment()
    {
        return $this->belongsTo(DutyAssignment::class);
    }

    public function fromOfficer()
    {
        return $this->belongsTo(User::class, 'from_officer_id');
    }

    public function toOfficer()
    {
        return $this->belongsTo(User::class, 'to_officer_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
