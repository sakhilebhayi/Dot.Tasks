<?php

namespace App\Console\Commands;

use App\Services\Automations;
use Illuminate\Console\Command;

class RunTaskRules extends Command
{
    protected $signature = 'tasks:run-rules';

    protected $description = 'Run delayed automation rules that have come due, and fire rules for tasks that went overdue.';

    public function handle(Automations $automations): int
    {
        $this->info('Ran '.$automations->sweep().' rule run(s).');

        return self::SUCCESS;
    }
}
