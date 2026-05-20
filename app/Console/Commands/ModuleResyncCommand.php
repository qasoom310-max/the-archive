<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Erp\Modules\ModuleManager;
use Illuminate\Console\Command;

final class ModuleResyncCommand extends Command
{
    protected $signature = 'module:resync {name}';

    protected $description = 'Re-reflect an installed module\'s models/fields/views into the ir_* registry (no schema changes)';

    public function handle(ModuleManager $manager): int
    {
        $name = (string) $this->argument('name');

        $manager->resyncRegistry($name);

        $this->components->info("Re-synced registry (models, fields, views) for '{$name}'.");

        return self::SUCCESS;
    }
}
