<?php

declare(strict_types=1);

namespace Modules\Pos\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Pos\Services\PosSessionManager;

/**
 * POS module provider. Loaded by the core ModuleServiceProvider only while
 * the module is installed; routes/views are auto-discovered by the engine.
 */
final class PosServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PosSessionManager::class);
    }

    public function boot(): void
    {
        //
    }
}
