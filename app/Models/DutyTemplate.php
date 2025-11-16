<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DutyTemplate extends Model
{
    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'start_time',
        'end_time',
        'required_officers',
        'default_days',
        'metadata',
        'created_by',
    ];

    protected $casts = [
        'default_days' => 'array',
        'metadata' => 'array',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
