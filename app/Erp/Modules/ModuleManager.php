<?php

declare(strict_types=1);

namespace App\Erp\Modules;

use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Enums\ModuleState;
use App\Erp\Registry\ModelDefinition;
use App\Models\Ir\IrModel;
use App\Models\Ir\IrModule;
use App\Models\Ir\IrUiView;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Discovers, installs and uninstalls modules — the runtime equivalent of
 * Odoo's module registry. Keeps `ir_module` / `ir_model` / `ir_model_fields`
 * / `ir_ui_view` in sync with what is installed.
 */
final class ModuleManager
{
    /** @var array<string, ModuleManifest>|null */
    private ?array $manifests = null;

    public function __construct(
        private readonly Filesystem $files,
        private readonly string $modulesPath,
        private readonly string $manifestFile,
        private readonly string $coreModule,
    ) {}

    /**
     * Scan the modules directory and parse every manifest found.
     *
     * @return array<string, ModuleManifest> keyed by technical name
     */
    public function discover(bool $fresh = false): array
    {
        if ($this->manifests !== null && ! $fresh) {
            return $this->manifests;
        }

        $manifests = [];

        if ($this->files->isDirectory($this->modulesPath)) {
            foreach ($this->files->directories($this->modulesPath) as $directory) {
                $manifestPath = $directory . DIRECTORY_SEPARATOR . $this->manifestFile;

                if (! $this->files->isFile($manifestPath)) {
                    continue;
                }

                $manifest = ModuleManifest::fromFile($manifestPath, $directory);
                $manifests[$manifest->name] = $manifest;
            }
        }

        return $this->manifests = $manifests;
    }

    public function find(string $name): ?ModuleManifest
    {
        return $this->discover()[$name] ?? null;
    }

    /**
     * Upsert every discovered module into `ir_module`, preserving the
     * lifecycle state of modules that already exist.
     *
     * @return int number of newly registered modules
     */
    public function sync(): int
    {
        $new = 0;

        foreach ($this->discover() as $manifest) {
            $module = IrModule::query()->firstWhere('name', $manifest->name);

            if ($module === null) {
                IrModule::query()->create([
                    'name' => $manifest->name,
                    'state' => ModuleState::Uninstalled,
                    ...$manifest->toModuleAttributes(),
                ]);
                $new++;

                continue;
            }

            $module->fill($manifest->toModuleAttributes())->save();
        }

        return $new;
    }

    /**
     * Install a module: resolve dependencies, run its migrations and register
     * its models / views into the `ir_*` registry.
     */
    public function install(string $name): void
    {
        $this->installResolving($name, []);
    }

    /**
     * @param list<string> $chain modules currently being resolved (cycle guard)
     */
    private function installResolving(string $name, array $chain): void
    {
        $manifest = $this->find($name);

        if ($manifest === null) {
            throw ModuleException::notFound($name);
        }

        if (in_array($name, $chain, true)) {
            throw ModuleException::circular($name);
        }

        $module = $this->ensureRegistered($manifest);

        if ($module->state === ModuleState::Installed) {
            return;
        }

        foreach ($manifest->depends as $dependency) {
            if ($dependency === $this->coreModule) {
                continue;
            }

            if ($this->find($dependency) === null) {
                throw ModuleException::missingDependency($name, $dependency);
            }

            $this->installResolving($dependency, [...$chain, $name]);
        }

        DB::transaction(function () use ($manifest, $module): void {
            $this->runMigrations($manifest);
            $this->registerModels($manifest);

            $module->update([
                'state' => ModuleState::Installed,
                'installed_version' => $manifest->version,
                'installed_at' => Carbon::now(),
            ]);
        });
    }

    /**
     * Uninstall a module: refuse if depended upon, roll back its migrations
     * and purge its registry rows.
     */
    public function uninstall(string $name): void
    {
        $manifest = $this->find($name);

        if ($manifest === null) {
            throw ModuleException::notFound($name);
        }

        $module = IrModule::query()->firstWhere('name', $name);

        if ($module === null || $module->state !== ModuleState::Installed) {
            return;
        }

        foreach ($this->discover() as $other) {
            if ($other->name === $name || ! in_array($name, $other->depends, true)) {
                continue;
            }

            $dependent = IrModule::query()->firstWhere('name', $other->name);

            if ($dependent !== null && $dependent->state === ModuleState::Installed) {
                throw ModuleException::dependents($name, $other->name);
            }
        }

        DB::transaction(function () use ($manifest, $module, $name): void {
            IrUiView::query()->where('module', $name)->delete();
            IrModel::query()->where('module', $name)->delete(); // cascades ir_model_fields

            $this->rollbackMigrations($manifest);

            $module->update([
                'state' => ModuleState::Uninstalled,
                'installed_version' => null,
                'installed_at' => null,
            ]);
        });
    }

    /**
     * Re-reflect an installed module's `DefinesIrModel` classes into the
     * registry (ir_model / ir_model_fields / ir_ui_view) WITHOUT touching
     * its tables or migrations. The Odoo `-u module` equivalent: use it
     * after changing a model's `irModelDefinition()` so stored views catch
     * up. No-op unless the module is installed.
     */
    public function resyncRegistry(string $name): void
    {
        $manifest = $this->find($name);

        if ($manifest === null) {
            throw ModuleException::notFound($name);
        }

        $module = IrModule::query()->firstWhere('name', $name);

        if ($module === null || $module->state !== ModuleState::Installed) {
            return;
        }

        DB::transaction(function () use ($manifest): void {
            $this->registerModels($manifest);
        });
    }

    private function ensureRegistered(ModuleManifest $manifest): IrModule
    {
        $module = IrModule::query()->firstWhere('name', $manifest->name);

        if ($module !== null) {
            return $module;
        }

        return IrModule::query()->create([
            'name' => $manifest->name,
            'state' => ModuleState::Uninstalled,
            ...$manifest->toModuleAttributes(),
        ]);
    }

    private function runMigrations(ModuleManifest $manifest): void
    {
        if (! $this->files->isDirectory($manifest->migrationsPath())) {
            return;
        }

        Artisan::call('migrate', [
            '--path' => $manifest->migrationsPath(),
            '--realpath' => true,
            '--force' => true,
        ]);
    }

    private function rollbackMigrations(ModuleManifest $manifest): void
    {
        if (! $this->files->isDirectory($manifest->migrationsPath())) {
            return;
        }

        Artisan::call('migrate:reset', [
            '--path' => $manifest->migrationsPath(),
            '--realpath' => true,
            '--force' => true,
        ]);
    }

    private function registerModels(ModuleManifest $manifest): void
    {
        foreach ($manifest->models as $class) {
            if (! class_exists($class) || ! is_a($class, DefinesIrModel::class, true)) {
                continue;
            }

            /** @var class-string<DefinesIrModel> $class */
            $this->persistDefinition($class::irModelDefinition(), $manifest->name);
        }
    }

    private function persistDefinition(ModelDefinition $definition, string $module): void
    {
        $irModel = IrModel::query()->updateOrCreate(
            ['model' => $definition->model],
            [...$definition->toAttributes(), 'module' => $module],
        );

        $irModel->fields()->delete();

        foreach ($definition->fields as $field) {
            $irModel->fields()->create($field->toAttributes());
        }

        IrUiView::query()->where('model', $definition->model)->delete();

        foreach ($definition->views as $view) {
            IrUiView::query()->create([
                ...$view->toAttributes(),
                'model' => $definition->model,
                'module' => $module,
            ]);
        }
    }
}
