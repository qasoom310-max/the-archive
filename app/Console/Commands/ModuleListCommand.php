<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Erp\Modules\ModuleManager;
use App\Models\Ir\IrModule;
use Illuminate\Console\Command;

final class ModuleListCommand extends Command
{
    protected $signature = 'module:list';

    protected $description = 'List discovered modules and their installation state';

    public function handle(ModuleManager $manager): int
    {
        $registered = IrModule::query()->get()->keyBy('name');

        $rows = [];

        foreach ($manager->discover() as $manifest) {
            /** @var IrModule|null $module */
            $module = $registered->get($manifest->name);

            $rows[] = [
                $manifest->name,
                $manifest->displayName,
                $manifest->version,
                $module?->state->label() ?? 'Not synced',
                $manifest->application ? 'app' : '—',
            ];
        }

        if ($rows === []) {
            $this->components->warn('No modules discovered under ' . config('erp.modules_path') . '.');

            return self::SUCCESS;
        }

        $this->table(['Name', 'Display Name', 'Version', 'State', 'Type'], $rows);

        return self::SUCCESS;
    }
}
