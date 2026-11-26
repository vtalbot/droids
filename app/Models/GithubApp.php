<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $app_id
 * @property string $slug
 * @property string $name
 * @property string|null $owner_login
 * @property string $html_url
 * @property string|null $client_id
 * @property string|null $client_secret
 * @property string $private_key
 * @property string|null $webhook_secret
 */
class GithubApp extends Model
{
    protected $guarded = [];

    protected $hidden = ['client_secret', 'private_key', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
            'private_key' => 'encrypted',
            'webhook_secret' => 'encrypted',
        ];
    }

    public static function current(): ?self
    {
        return static::query()->latest('id')->first();
    }

    public function installUrl(): string
    {
        return "https://github.com/apps/{$this->slug}/installations/new";
    }
}
