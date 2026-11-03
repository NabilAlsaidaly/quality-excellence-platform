<?php

namespace App\Authorization;

use Illuminate\Console\Command;

/** Package mutation commands must not bypass the internal management boundary. */
final class UnavailablePermissionCommand extends Command
{
    public function __construct(string $name)
    {
        $this->signature = $name;
        parent::__construct();
    }

    public function handle(): int
    {
        $this->error('Unavailable: owner-approved administration policy is required.');

        return self::FAILURE;
    }
}
