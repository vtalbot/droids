<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $feature_id
 * @property int $number
 * @property string $title
 * @property string $column
 * @property list<string>|null $labels
 * @property int|null $builder_id
 * @property int|null $reviewer_id
 * @property string|null $engine
 * @property int $position
 * @property int|null $github_id
 * @property string $state
 * @property string|null $url
 * @property Carbon|null $triaged_at
 * @property string|null $question what the triage droid asked before the card can be built
 */
class Task extends Model
{
    public const COLUMNS = ['backlog', 'ready', 'progress', 'review', 'done'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['labels' => 'array', 'triaged_at' => 'datetime'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Feature, $this> */
    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }

    /** @return BelongsTo<Droid, $this> */
    public function builder(): BelongsTo
    {
        return $this->belongsTo(Droid::class, 'builder_id');
    }

    /** @return BelongsTo<Droid, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Droid::class, 'reviewer_id');
    }

    /** @return HasOne<PullRequest, $this> */
    public function pullRequest(): HasOne
    {
        return $this->hasOne(PullRequest::class)->latestOfMany();
    }
}
