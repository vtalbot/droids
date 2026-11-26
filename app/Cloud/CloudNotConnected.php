<?php

namespace App\Cloud;

use RuntimeException;

class CloudNotConnected extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Add a Laravel Cloud API token in Settings > Laravel Cloud first.');
    }
}
