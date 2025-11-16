<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ReviewAction extends Model
{
    use HasFactory;

    protected $fillable = [
        'review_request_id',
        'actor_user_id',
        'actor_org_id',
        'action',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function request()
    {
        return $this->belongsTo(ReviewRequest::class, 'review_request_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function actorOrg()
    {
        return $this->belongsTo(Organization::class, 'actor_org_id');
    }
}
