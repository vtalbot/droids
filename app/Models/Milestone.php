<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property string|null $goal
 * @property Carbon|null $due_on
 * @property string $state
 * @property int $position
 * @property int|null $github_number
 */
class Milestone extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['due_on' => 'date'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<Feature, $this> */
    public function features(): HasMany
    {
        return $this->hasMany(Feature::class)->orderBy('position');
    }
}
