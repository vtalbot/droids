<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $slug
 * @property string $unit
 * @property string $name
 * @property string $provider
 * @property string $model
 * @property string $role
 * @property string $status
 * @property int|null $project_id
 * @property int|null $task_id
 * @property string $step
 * @property float $progress
 * @property int $minutes
 * @property float $cost
 * @property int $seed
 * @property string $autonomy
 * @property string|null $instructions
 * @property float|null $daily_budget
 */
class Droid extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'progress' => 'float',
            'cost' => 'float',
            'daily_budget' => 'float',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return HasMany<Activity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /** @return HasMany<ChatMessage, $this> */
    public function chatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }
}
