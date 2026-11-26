<?php

namespace App\Support;

use App\Models\User;

/**
 * Droids is a private control room: sign-up is open only until the first
 * account exists, unless DROIDS_OPEN_REGISTRATION is turned on.
 */
class Registration
{
    public static function isOpen(): bool
    {
        return (bool) config('droids.open_registration') || ! User::query()->exists();
    }
}
