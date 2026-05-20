<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Erp\Modules\ModuleException;
use App\Erp\Modules\ModuleManager;
use Illuminate\Console\Command;

final class ModuleUninstallCommand extends Command
{
    protected $signature = 'module:uninstall {name : Technical name of the module}';

    protected $description = 'Uninstall a module: roll back migrations and purge its registry rows';

    public function handle(ModuleManager $manager): int
    {
        $name = (string) $this->argument('name');

        try {
            $manager->uninstall($name);
        } catch (ModuleException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Module \"{$name}\" uninstalled.");

        return self::SUCCESS;
    }
}
