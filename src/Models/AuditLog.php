<?php

namespace Sopheak\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'title',
        'subject',
        'recap',
        'old_data',
        'new_data',
        'entity_name',
        'entity_type',
        'entity_id',
        'user_id',
        'metadata',
        'event',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'old_data' => 'array',
        'new_data' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the user that performed the action.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model', 'App\Models\User'));
    }

    /**
     * Get the module relationship (if exists).
     * This is a placeholder for potential future module relationship.
     */
    public function module(): BelongsTo
    {
        // Return a dummy relationship for now
        return $this->belongsTo(config('auth.providers.users.model', 'App\Models\User'), 'user_id');
    }
}