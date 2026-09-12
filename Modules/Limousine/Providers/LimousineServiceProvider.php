<?php

declare(strict_types=1);

namespace Modules\Limousine\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Limousine\Support\DriverAliases;

/**
 * Limousine module service provider. Loaded by the core ModuleServiceProvider
 * only while the module is installed. Routes and views auto-load from the
 * module's `routes/` and `resources/views/`, so this stays thin.
 */
final class LimousineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One reader of the old-system driver names per request: the queue asks
        // it once per row, and the mapping is a table of a hundred-odd rows
        // that nothing changes mid-request except the screen that edits it.
        $this->app->singleton(DriverAliases::class);
    }

    public function boot(): void
    {
        //
    }
}
