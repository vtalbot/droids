<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $slug
 * @property string $provider
 * @property string $name
 * @property string|null $account
 * @property string|null $auth
 * @property string $color
 * @property bool $enabled
 * @property list<array{label: string, used: float, resets: string}>|null $windows
 * @property list<float>|null $week
 * @property float|null $spend_used
 * @property float|null $spend_cap
 */
class Subscription extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'windows' => 'array',
            'week' => 'array',
            'spend_used' => 'float',
            'spend_cap' => 'float',
        ];
    }
}
