<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Erp\Modules\ModuleManager;
use Illuminate\Console\Command;

final class ModuleSyncCommand extends Command
{
    protected $signature = 'module:sync';

    protected $description = 'Register newly discovered modules into the ir_module registry';

    public function handle(ModuleManager $manager): int
    {
        $new = $manager->sync();

        $this->components->info(
            $new === 0
                ? 'Registry already up to date.'
                : "Registered {$new} new module(s) into ir_module.",
        );

        return self::SUCCESS;
    }
}
