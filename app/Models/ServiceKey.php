<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An API key for an outside service: "cloudflare" for DNS, "resend" for email domains.
 *
 * @property int $id
 * @property string $service
 * @property string $token
 * @property string|null $label
 */
class ServiceKey extends Model
{
    public const SERVICES = ['cloudflare', 'resend'];

    protected $guarded = [];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['token' => 'encrypted'];
    }

    public static function for(string $service): ?self
    {
        return self::where('service', $service)->first();
    }
}
