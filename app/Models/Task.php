<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'payload',
        'priority',
    ];

    protected function casts(): array
    {
        return [
            'type' => TaskType::class,
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'payload' => 'array',
            'attempts' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(TaskLog::class);
    }

    public function scopeForUser(Builder $query, int|string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeWithStatus(Builder $query, TaskStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    public function scopeWithPriority(Builder $query, TaskPriority $priority): Builder
    {
        return $query->where('priority', $priority->value);
    }

    public function executionKey(): string
    {
        return hash('sha256', json_encode([
            'user_id' => $this->user_id,
            'type' => $this->type instanceof TaskType ? $this->type->value : $this->type,
            'payload' => $this->payload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
