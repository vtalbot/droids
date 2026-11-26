<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $installation_id
 * @property string $account_login
 * @property string $account_type
 * @property string $repository_selection
 * @property Carbon|null $suspended_at
 */
class GithubInstallation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['suspended_at' => 'datetime'];
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
