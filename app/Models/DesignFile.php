<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A file of a project's design: the design system, its tokens, or a screen mockup.
 *
 * @property int $id
 * @property int $project_id
 * @property string $path
 * @property string $content
 * @property int|null $droid_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read Droid|null $droid
 */
class DesignFile extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Droid, $this> */
    public function droid(): BelongsTo
    {
        return $this->belongsTo(Droid::class);
    }
}
