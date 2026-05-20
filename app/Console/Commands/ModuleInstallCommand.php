<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Erp\Modules\ModuleException;
use App\Erp\Modules\ModuleManager;
use Illuminate\Console\Command;

final class ModuleInstallCommand extends Command
{
    protected $signature = 'module:install {name : Technical name of the module}';

    protected $description = 'Install a module: resolve dependencies, run migrations and register the model registry';

    public function handle(ModuleManager $manager): int
    {
        $name = (string) $this->argument('name');

        try {
            $manager->install($name);
        } catch (ModuleException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Module \"{$name}\" installed.");

        return self::SUCCESS;
    }
}
