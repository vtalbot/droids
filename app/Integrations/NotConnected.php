<?php

namespace App\Integrations;

use RuntimeException;

/** No API key is saved for the service a call needs. */
class NotConnected extends RuntimeException
{
    public static function service(string $name): self
    {
        return new self("Add a {$name} API token in Settings > DNS first.");
    }
}
