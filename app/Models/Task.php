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
 * @property list<int>|null $blocked_by numbers of the cards in the project that must be merged first
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

    /**
     * The card numbers an issue body says this card waits on:
     * "Blocked by #12", "Depends on #12 and #14", "After #3, #4".
     *
     * @return list<int>
     */
    public static function blockersIn(?string $body): array
    {
        $numbers = [];
        if (preg_match_all('/\b(?:blocked\s+by|depends\s+on|after)\s*:?\s*((?:#\d+(?:\s*(?:,|and|&)?\s*)?)+)/i', (string) $body, $m)) {
            foreach ($m[1] as $list) {
                preg_match_all('/#(\d+)/', $list, $n);
                $numbers = [...$numbers, ...array_map('intval', $n[1])];
            }
        }

        return array_values(array_unique($numbers));
    }

    /**
     * The cards blocking this one that are not merged yet (not done).
     *
     * @return list<int>
     */
    public function openBlockers(): array
    {
        if (! $this->blocked_by) {
            return [];
        }

        return array_values(array_map('intval', self::where('project_id', $this->project_id)->whereIn('number', $this->blocked_by)
            ->where('column', '!=', 'done')->orderBy('number')->pluck('number')->all()));
    }

    /**
     * Set the blockers, keeping only other cards of the same project.
     *
     * @param  list<int>  $numbers
     */
    public function blockOn(array $numbers): void
    {
        $numbers = array_values(array_filter(array_unique(array_map('intval', $numbers)), fn (int $n) => $n !== $this->number));
        $known = $numbers ? array_map('intval', self::where('project_id', $this->project_id)->whereIn('number', $numbers)->pluck('number')->all()) : [];
        sort($known);
        $this->update(['blocked_by' => $known ?: null]);
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return ['labels' => 'array', 'triaged_at' => 'datetime', 'priority' => 'integer', 'blocked_by' => 'array'];
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
