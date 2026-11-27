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
 * @property string|null $question_for the role of the droid the question went to; null for the person
 * @property string|null $answer what that droid answered
 * @property int|null $priority 1 urgent to 4 low, see PRIORITIES
 */
class Task extends Model
{
    public const COLUMNS = ['backlog', 'ready', 'progress', 'review', 'done'];

    /** Priority names by stored value; an unset priority counts as medium. */
    public const PRIORITIES = [1 => 'urgent', 2 => 'high', 3 => 'medium', 4 => 'low'];

    /** SQL that orders tasks highest priority first. */
    public const BY_PRIORITY = 'coalesce(priority, 3)';

    public function priorityName(): ?string
    {
        return $this->priority ? self::PRIORITIES[$this->priority] ?? null : null;
    }

    public static function priorityValue(mixed $name): ?int
    {
        $v = array_search(is_string($name) ? strtolower(trim($name)) : $name, self::PRIORITIES, true);

        return $v === false ? null : $v;
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return ['labels' => 'array', 'triaged_at' => 'datetime', 'priority' => 'integer'];
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
