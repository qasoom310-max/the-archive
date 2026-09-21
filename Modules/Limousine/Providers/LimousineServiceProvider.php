<?php

declare(strict_types=1);

namespace Modules\Limousine\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Limousine\Console\FindDuplicateTrips;
use Modules\Limousine\Console\ImportLegacyBookings;
use Modules\Limousine\Console\ImportLegacyInvoices;
use Modules\Limousine\Console\ImportLegacyQuotations;
use Modules\Limousine\Console\ImportLegacyReceipts;
use Modules\Limousine\Console\MatchDriverNames;
use Modules\Limousine\Console\ReviewDuplicateTrips;
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
        if ($this->app->runningInConsole()) {
            $this->commands([MatchDriverNames::class, ImportLegacyBookings::class, ImportLegacyReceipts::class, ImportLegacyInvoices::class, ImportLegacyQuotations::class, FindDuplicateTrips::class, ReviewDuplicateTrips::class]);
        }
    }
}
