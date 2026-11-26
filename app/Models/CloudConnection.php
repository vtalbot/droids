<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The Laravel Cloud API token Droids uses, and the organization it belongs to.
 *
 * @property int $id
 * @property string $token
 * @property string $organization_name
 * @property string|null $organization_slug
 */
class CloudConnection extends Model
{
    protected $guarded = [];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['token' => 'encrypted'];
    }

    public static function current(): ?self
    {
        return self::latest('id')->first();
    }
}
