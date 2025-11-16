<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrganizationUser extends Model
{
    // If you like, add: use HasFactory;
    protected $table = 'organization_user';

    protected $fillable = [
        'organization_id',
        'user_id',
        'role',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
