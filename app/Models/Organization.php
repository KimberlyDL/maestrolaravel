<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'logo',
        'website',
        'settings',
        'invite_code',
        'mission',
        'vision',
        'auto_accept_invites',
        'public_profile',
        'member_can_invite',
        'log_retention_days',
        'is_archived',
        'archived_at',
    ];

    protected $casts = [
        'settings' => 'array',
        'auto_accept_invites' => 'boolean',
        'public_profile' => 'boolean',
        'member_can_invite' => 'boolean',
        'log_retention_days' => 'integer',
        'is_archived' => 'boolean',
        'archived_at' => 'datetime',
    ];

    /**
     * Primary member relation (users in this org) with pivot role.
     * Keep this as the canonical relation name used by controllers.
     */
    public function members()
    {
        return $this->belongsToMany(User::class, 'organization_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Back-compat alias. Anywhere that still calls users() will work.
     */
    public function users()
    {
        return $this->members();
    }

    /**
     * Direct access to pivot records
     */
    public function memberships()
    {
        return $this->hasMany(OrganizationUser::class);
    }

    /**
     * Documents belonging to this organization
     */
    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    /**
     * Review requests in this organization
     */
    public function reviewRequests()
    {
        return $this->hasMany(ReviewRequest::class, 'publisher_org_id');
    }

    /**
     * Get admins of this organization
     */
    public function admins()
    {
        return $this->users()->wherePivot('role', 'admin');
    }

    /**
     * Check if user is member of this organization
     */
    public function hasMember($userId): bool
    {
        return $this->users()->where('users.id', $userId)->exists();
    }

    /**
     * Get user's role in this organization
     */
    public function getUserRole($userId): ?string
    {
        $membership = $this->memberships()->where('user_id', $userId)->first();
        return $membership?->role;
    }

    /**
     * Get the logo URL
     */
    public function getLogoUrlAttribute()
    {
        if (!$this->logo) {
            return null;
        }

        if (str_starts_with($this->logo, 'http')) {
            return $this->logo;
        }

        return Storage::disk('s3')->url($this->logo);
    }

    /**
     * Scope for non-archived organizations
     */
    public function scopeActive($query)
    {
        return $query->where('is_archived', false);
    }

    /**
     * Scope for archived organizations
     */
    public function scopeArchived($query)
    {
        return $query->where('is_archived', true);
    }
}
