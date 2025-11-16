<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// app/Models/DocumentVersion.php
class DocumentVersion extends Model
{
    protected $fillable = ['document_id', 'version_number', 'file_path', 'note', 'uploaded_by'];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
