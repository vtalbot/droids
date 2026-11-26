<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $droid_id
 * @property string $text
 * @property Carbon|null $created_at
 */
class Activity extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<Droid, $this> */
    public function droid(): BelongsTo
    {
        return $this->belongsTo(Droid::class);
    }
}
