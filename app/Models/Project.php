<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $repo
 * @property string $board
 * @property string $color
 * @property int|null $github_installation_id
 * @property int|null $github_repo_id
 * @property Carbon|null $synced_at
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
            'synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<GithubInstallation, $this> */
    public function installation(): BelongsTo
    {
        return $this->belongsTo(GithubInstallation::class, 'github_installation_id');
    }

    public function isLinked(): bool
    {
        return $this->github_installation_id !== null;
    }

    /** @return array{0: string, 1: string} */
    public function ownerAndName(): array
    {
        [$owner, $name] = explode('/', $this->repo, 2);

        return [$owner, $name];
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
