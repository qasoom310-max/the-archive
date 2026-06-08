<?php

declare(strict_types=1);

namespace Modules\Purchases\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Purchases\Services\PurchaseConfirmer;

/**
 * Purchases module provider. Loaded by the core ModuleServiceProvider only
 * while the module is installed; routes/views/components are auto-discovered
 * by the engine.
 *
 * The Purchase → Accounting binding lives in
 * {@see \Modules\Accounting\Providers\AccountingServiceProvider} (the
 * listener's own module owns the wiring, matching the POS → Accounting setup).
 */
final class PurchasesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PurchaseConfirmer::class);
    }

    public function boot(): void
    {
        //
    }
}
