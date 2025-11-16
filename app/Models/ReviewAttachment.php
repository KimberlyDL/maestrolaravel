<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ReviewAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'review_request_id',
        'review_comment_id',
        'uploaded_by',
        'file_path',
        'label',
    ];

    public function request()
    {
        return $this->belongsTo(ReviewRequest::class, 'review_request_id');
    }

    public function comment()
    {
        return $this->belongsTo(ReviewComment::class, 'review_comment_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
