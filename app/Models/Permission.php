<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    protected $fillable = [
        'name',
        'display_name',
        'description',
        'category',
    ];

    /**
     * Users who have this permission (through pivot)
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_user_permissions')
            ->withPivot('organization_id', 'granted_by', 'granted_at')
            ->withTimestamps();
    }

    /**
     * Get all permissions grouped by category
     */
    public static function getAllGrouped(): array
    {
        return self::all()
            ->groupBy('category')
            ->map(fn($perms) => $perms->map(fn($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'display_name' => $p->display_name,
                'description' => $p->description,
                'category' => $p->category,
            ]))
            ->toArray();
    }
}
