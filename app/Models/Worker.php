<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A machine that runs droids: it polls for queued runs with its own token.
 *
 * @property int $id
 * @property string $name
 * @property string $token_hash
 * @property list<string>|null $subscriptions
 * @property string|null $version
 * @property Carbon|null $last_seen_at
 */
class Worker extends Model
{
    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'subscriptions' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * Create a worker and return it with its plain token, which is shown once.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(string $name): array
    {
        $token = 'dw_'.Str::random(40);
        $worker = static::create(['name' => $name, 'token_hash' => hash('sha256', $token)]);

        return [$worker, $token];
    }

    public static function findByToken(?string $token): ?self
    {
        return $token ? static::where('token_hash', hash('sha256', $token))->first() : null;
    }

    public function online(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->gt(now()->subMinutes(2));
    }

    /** @return HasMany<Run, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }
}
