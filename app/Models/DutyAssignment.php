<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DutyAssignment extends Model
{
    protected $fillable = [
        'duty_schedule_id',
        'officer_id',
        'status',
        'notes',
        'confirmed_at',
        'check_in_at',
        'check_out_at',
        'assigned_by',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
        'check_in_at' => 'datetime',
        'check_out_at' => 'datetime',
    ];

    public function dutySchedule()
    {
        return $this->belongsTo(DutySchedule::class);
    }

    public function officer()
    {
        return $this->belongsTo(User::class, 'officer_id');
    }

    public function assigner()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function swapRequests()
    {
        return $this->hasMany(DutySwapRequest::class);
    }
}
