<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $droid_id
 * @property int $task_id
 * @property int|null $subscription_id
 * @property int|null $worker_id
 * @property string $kind
 * @property string $status
 * @property string|null $branch
 * @property int|null $pr_number
 * @property int $attempts
 * @property string|null $summary
 * @property string|null $error
 * @property string|null $log
 * @property float $cost
 * @property int $tokens_in
 * @property int $tokens_out
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $heartbeat_at
 * @property CarbonInterface|null $finished_at
 * @property-read Droid $droid
 * @property-read Task $task
 * @property-read Subscription|null $subscription
 * @property-read Worker|null $worker
 */
class Run extends Model
{
    public const ACTIVE = ['queued', 'running'];

    /** Keep the stored log to its last 64 KB. */
    public const LOG_LIMIT = 65536;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'cost' => 'float',
            'started_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Droid, $this> */
    public function droid(): BelongsTo
    {
        return $this->belongsTo(Droid::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return BelongsTo<Worker, $this> */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function appendLog(string $text): void
    {
        $log = ($this->log ?? '').$text;
        $this->log = strlen($log) > self::LOG_LIMIT ? substr($log, -self::LOG_LIMIT) : $log;
    }
}
