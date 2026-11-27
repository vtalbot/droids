<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item in a droid conversation: user or agent text, tool call, task, plan, etc.
 *
 * @property int $id
 * @property int|null $droid_id null once the droid is deleted
 * @property string|null $topic
 * @property string $kind
 * @property array<string, mixed> $payload
 */
class ChatMessage extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    /** @return BelongsTo<Droid, $this> */
    public function droid(): BelongsTo
    {
        return $this->belongsTo(Droid::class);
    }
}
