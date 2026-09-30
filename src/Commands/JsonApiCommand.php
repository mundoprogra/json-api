<?php

namespace MundoProgra\JsonApi\Commands;

use Illuminate\Console\Command;

class JsonApiCommand extends Command
{
    public $signature = 'json-api';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
