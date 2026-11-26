<?php

namespace App\GitHub;

use RuntimeException;

class GitHubNotConfigured extends RuntimeException
{
    public function __construct(string $message = 'Set up the GitHub App first, under Settings > GitHub.')
    {
        parent::__construct($message);
    }
}
