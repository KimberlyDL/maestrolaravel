<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DutyAvailability extends Model
{
    protected $table = 'duty_availability';
    protected $fillable = [
        'organization_id',
        'user_id',
        'date',
        'start_time',
        'end_time',
        'availability_type',
        'reason',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
