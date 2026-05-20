<?php

declare(strict_types=1);

namespace App\Providers;

use App\Erp\Enums\ModuleState;
use App\Erp\Modules\ModuleManager;
use App\Models\Ir\IrModule;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Livewire\Component;
use Livewire\Livewire;
use Livewire\Mechanisms\ComponentRegistry;
use Throwable;

/**
 * Boots the modular addon system: binds the ModuleManager and activates
 * every *installed* module's service providers, views and routes.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleManager::class, function (): ModuleManager {
            return new ModuleManager(
                $this->app->make(Filesystem::class),
                $this->configString('erp.modules_path', base_path('Modules')),
                $this->configString('erp.manifest_file', 'module.json'),
                $this->configString('erp.core_module', 'base'),
            );
        });
    }

    public function boot(): void
    {
        $manager = $this->app->make(ModuleManager::class);

        // Filesystem-only: a module's view namespace always resolves
        // (harmless when uninstalled, and keeps static analysis honest).
        foreach ($manager->discover() as $manifest) {
            $views = $manifest->path . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views';

            if (is_dir($views)) {
                $this->loadViewsFrom($views, $manifest->name);
            }
        }

        // Activation (providers + routes) requires the module to be
        // *installed*, so it is gated on the engine tables existing.
        try {
            if (! Schema::hasTable('ir_module')) {
                return;
            }
        } catch (Throwable) {
            return;
        }

        $installed = IrModule::query()
            ->where('state', ModuleState::Installed)
            ->orderBy('sequence')
            ->pluck('name');

        foreach ($installed as $name) {
            $manifest = $manager->find((string) $name);

            if ($manifest === null) {
                continue;
            }

            foreach ($manifest->providers as $provider) {
                if (class_exists($provider)) {
                    $this->app->register($provider);
                }
            }

            $this->registerModuleComponents($manifest->path);

            $routes = $manifest->path . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'web.php';

            if (is_file($routes)) {
                // Module routes must run inside the `web` group so they get
                // session, cookies and CSRF (StartSession et al.). Without
                // it `Auth` can't read the session and every module page
                // bounces an authenticated user back to login → home.
                Route::middleware('web')->group($routes);
            }
        }
    }

    /**
     * Register a module's Livewire components under the exact name
     * Livewire derives from each class. Module components live outside
     * `App\Livewire`, so without an explicit registration the first GET
     * render works but the follow-up Livewire POST cannot map the
     * snapshot name back to the class (ComponentNotFoundException).
     */
    private function registerModuleComponents(string $modulePath): void
    {
        $dir = $modulePath . DIRECTORY_SEPARATOR . 'Livewire';

        if (! is_dir($dir)) {
            return;
        }

        // PSR-4: `Modules\` → `Modules/`; the folder is the StudlyCase
        // module namespace segment (e.g. .../Modules/Pos → Modules\Pos).
        $namespace = 'Modules\\' . basename($modulePath) . '\\Livewire\\';
        $registry = $this->app->make(ComponentRegistry::class);

        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            $class = $namespace . pathinfo($file, PATHINFO_FILENAME);

            if (! class_exists($class) || ! is_subclass_of($class, Component::class)) {
                continue;
            }

            Livewire::component($registry->getName($class), $class);
        }
    }

    private function configString(string $key, string $default): string
    {
        $value = config($key, $default);

        return is_string($value) ? $value : $default;
    }
}
