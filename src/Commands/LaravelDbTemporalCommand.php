<?php

namespace Guggach\LaravelDbTemporal\Commands;

use Illuminate\Console\Command;

class LaravelDbTemporalCommand extends Command
{
    public $signature = 'laravel-db-temporal';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
