<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $slug
 * @property string $provider
 * @property string $name
 * @property string|null $account
 * @property string|null $auth
 * @property string|null $api_key
 * @property string $color
 * @property bool $enabled
 * @property Carbon|null $limited_until
 * @property list<array{label: string, used: float, resets: string}>|null $windows
 * @property list<float>|null $week
 * @property float|null $spend_used
 * @property float|null $spend_cap
 */
class Subscription extends Model
{
    protected $guarded = [];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'limited_until' => 'datetime',
            'api_key' => 'encrypted',
            'windows' => 'array',
            'week' => 'array',
            'spend_used' => 'float',
            'spend_cap' => 'float',
        ];
    }

    public function usesApiKey(): bool
    {
        return $this->api_key !== null;
    }

    public function isLimited(): bool
    {
        return $this->limited_until !== null && $this->limited_until->isFuture();
    }
}
