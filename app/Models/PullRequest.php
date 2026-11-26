<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $task_id
 * @property int $number
 * @property string $title
 * @property string $branch
 * @property int|null $github_id
 * @property string|null $head_sha
 * @property string|null $url
 * @property string $state
 * @property int $additions
 * @property int $deletions
 * @property int $commits
 * @property int $files
 * @property list<array{name: string, state: string, dur?: string, job?: int|null, url?: string|null}>|null $checks
 * @property list<array{by: string, state: string}>|null $reviews
 */
class PullRequest extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['checks' => 'array', 'reviews' => 'array'];
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
