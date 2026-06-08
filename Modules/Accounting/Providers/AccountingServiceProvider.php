<?php

declare(strict_types=1);

namespace Modules\Accounting\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Accounting\Listeners\RecordPosSaleInJournal;
use Modules\Accounting\Listeners\RecordPurchaseInJournal;
use Modules\Accounting\Services\FinancialReports;
use Modules\Accounting\Services\JournalPoster;
use Modules\Accounting\Services\SequenceGenerator;
use Modules\Pos\Events\PosOrderPaid;

/**
 * Accounting module provider. Loaded by the core ModuleServiceProvider
 * only while the module is installed; routes/views are auto-discovered
 * by the engine.
 *
 * Listens for cross-module business events (POS sale paid, Purchase
 * invoice confirmed) and drops the matching journal entries. Wiring
 * happens here rather than `EventServiceProvider` because Accounting
 * is a module — the binding must come and go with `module:install` /
 * `module:uninstall`.
 */
final class AccountingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/accounting.php', 'accounting');

        // Services are stateless aside from the cached config — singletons
        // keep allocations down on report-heavy pages.
        $this->app->singleton(SequenceGenerator::class);
        $this->app->singleton(JournalPoster::class);
        $this->app->singleton(FinancialReports::class);
    }

    public function boot(): void
    {
        // POS → Accounting. Only registered while both modules are installed
        // (Accounting's boot only runs in that case; the listener itself
        // resolves account ids lazily, so a missing COA row surfaces as a
        // useful "configure mapping" exception rather than a fatal boot).
        Event::listen(PosOrderPaid::class, [RecordPosSaleInJournal::class, 'handle']);

        // Purchase → Accounting. The Purchases module fires
        // `PurchaseInvoiceConfirmed` (carrying the Purchase as `$invoice`)
        // when a vendor bill is confirmed; this books Dr Inventory|Purchase
        // Expense / Cr Accounts Payable. Registered by the string event name
        // so Accounting carries NO compile-time dependency on Purchases — if
        // that module isn't installed the event simply never fires.
        Event::listen(
            'Modules\Purchases\Events\PurchaseInvoiceConfirmed',
            [RecordPurchaseInJournal::class, 'handle'],
        );
    }
}
