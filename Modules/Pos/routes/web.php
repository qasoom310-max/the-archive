<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Pos\Http\Controllers\PosProductExportController;
use Modules\Pos\Http\Controllers\PosProductImportTemplateController;
use Modules\Pos\Livewire\KitchenDisplay;
use Modules\Pos\Livewire\PosCategories;
use Modules\Pos\Livewire\PosCategoryForm;
use Modules\Pos\Livewire\PosHome;
use Modules\Pos\Livewire\PosOrders;
use Modules\Pos\Livewire\PosProductForm;
use Modules\Pos\Livewire\PosProductImport;
use Modules\Pos\Livewire\PosProducts;
use Modules\Pos\Livewire\PosReporting;
use Modules\Pos\Livewire\PosSessionPage;
use Modules\Pos\Livewire\PosTerminal;

Route::middleware('auth')->group(function (): void {
    Route::get('/app/pos', PosHome::class)->name('pos.home');

    // Reporting dashboard — KPI strip (revenue / orders / AOV) + a
    // preset-scoped orders list. Reached via the 3-dot menu on POS Orders.
    Route::get('/app/pos/reporting', PosReporting::class)->name('pos.reporting');

    // Sidebar resource entries (driven by the registered ir_models):
    // pos.order / pos.product / pos.session → these index pages.
    Route::get('/app/pos/order', PosOrders::class)->name('pos.order.index');
    Route::get('/app/pos/product', PosProducts::class)->name('pos.product.index');
    Route::get('/app/pos/category', PosCategories::class)->name('pos.category.index');
    Route::get('/app/pos/session', PosHome::class)->name('pos.session.index');

    Route::get('/app/pos/category/new', PosCategoryForm::class)->name('pos.category.create');
    Route::get('/app/pos/category/{id}', PosCategoryForm::class)
        ->whereNumber('id')->name('pos.category.edit');

    Route::get('/app/pos/session/{session}/terminal', PosTerminal::class)
        ->whereNumber('session')->name('pos.terminal');

    Route::get('/app/pos/session/{id}', PosSessionPage::class)
        ->whereNumber('id')->name('pos.session');

    Route::get('/app/pos/product/new', PosProductForm::class)->name('pos.product.create');

    // Bulk import / export — MUST register before the /{id} wildcard so
    // 'import', 'export', 'new' aren't captured as numeric ids. The
    // template + export routes are even more specific (controllers, not
    // Livewire pages, so they stream files directly).
    Route::get('/app/pos/product/import/template', PosProductImportTemplateController::class)
        ->name('pos.product.import.template');
    Route::get('/app/pos/product/export', PosProductExportController::class)
        ->name('pos.product.export');
    Route::get('/app/pos/product/import', PosProductImport::class)->name('pos.product.import');

    Route::get('/app/pos/product/{id}', PosProductForm::class)
        ->whereNumber('id')->name('pos.product.edit');

    // Kitchen Display Screen — same component, one URL per station so each
    // staff screen can be bookmarked / pinned. `whereIn('station', ...)`
    // bounces unknown station names to a 404 instead of bubbling an
    // InvalidArgumentException from PrepStation::from() into the layout.
    Route::get('/app/pos/kitchen/{station}', KitchenDisplay::class)
        ->whereIn('station', ['kitchen', 'shisha'])
        ->name('pos.kitchen');
});
