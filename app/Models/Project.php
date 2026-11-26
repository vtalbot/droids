<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $repo
 * @property string $board
 * @property string $color
 * @property array<string, mixed>|null $stats
 * @property array<string, mixed>|null $cloud_snapshot
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Project extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'stats' => 'array',
            'cloud_snapshot' => 'array',
        ];
    }

    /** @return HasMany<Milestone, $this> */
    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class)->orderBy('position');
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** @return HasMany<Droid, $this> */
    public function droids(): HasMany
    {
        return $this->hasMany(Droid::class);
    }
}
