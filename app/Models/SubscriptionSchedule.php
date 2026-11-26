<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Weekly grid of which subscription an engine draws from.
 *
 * @property int $id
 * @property string $provider
 * @property list<list<string>> $grid grid[day][hour] = subscription slug, "" when paused
 */
class SubscriptionSchedule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['grid' => 'array'];
    }
}
