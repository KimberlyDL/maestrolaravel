<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// app/Models/ReviewComment.php
class ReviewComment extends Model
{
    protected $fillable = ['review_request_id', 'author_user_id', 'author_org_id', 'body', 'is_internal', 'parent_id'];

    public function request()
    {
        return $this->belongsTo(ReviewRequest::class);
    }
    public function author()
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
    public function attachments()
    {
        return $this->hasMany(ReviewAttachment::class);
    }
}
