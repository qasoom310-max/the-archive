<?php

declare(strict_types=1);

namespace Modules\Inventory\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Inventory module provider. Loaded by the core ModuleServiceProvider
 * only while installed; routes/views/Livewire are auto-wired by the
 * engine.
 */
final class InventoryServiceProvider extends ServiceProvider
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
