<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use HasFactory;

    // Allow mass assignment for these fields
    protected $fillable = [
        'user_id',
        'organization_id',
        'type',
        'title',
        'message',
        'notifiable_type',
        'notifiable_id',
        'action_url',
        'data',
        'is_read',
        'read_at',
        'priority'
    ];

    protected $casts = [
        'data' => 'array',
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    /**
     * RELATIONSHIPS
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    // Polymorphic relationship (for linking to DutyAssignment, ReviewRequest, etc.)
    public function notifiable()
    {
        return $this->morphTo();
    }

    /**
     * SCOPES (This fixes the 500 Error)
     * These allow you to call Notification::forUser() in the controller
     */

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    public function scopeForOrganization($query, $orgId)
    {
        return $query->where('organization_id', $orgId);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * HELPER METHODS
     */
    public function markAsRead()
    {
        $this->update([
            'is_read' => true,
            'read_at' => now(),
        ]);
    }

    public function markAsUnread()
    {
        $this->update([
            'is_read' => false,
            'read_at' => null,
        ]);
    }
}