<?php

declare(strict_types=1);

namespace Modules\Limousine\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Limousine module service provider. Loaded by the core ModuleServiceProvider
 * only while the module is installed. Routes and views auto-load from the
 * module's `routes/` and `resources/views/`, so this stays thin.
 */
final class LimousineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
