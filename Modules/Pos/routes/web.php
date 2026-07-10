<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Pos\Http\Controllers\PosDamageReportExportController;
use Modules\Pos\Http\Controllers\PosProductExportController;
use Modules\Pos\Http\Controllers\PosProductImportTemplateController;
use Modules\Pos\Http\Controllers\PosReceiptPrintController;
use Modules\Pos\Http\Controllers\PosStockReportExportController;
use Modules\Pos\Http\Controllers\PosStockReportPrintController;
use Modules\Pos\Livewire\KitchenDisplay;
use Modules\Pos\Livewire\PosCategories;
use Modules\Pos\Livewire\PosCategoryForm;
use Modules\Pos\Livewire\PosCondimentForm;
use Modules\Pos\Livewire\PosCondiments;
use Modules\Pos\Livewire\PosCustomerDiscountForm;
use Modules\Pos\Livewire\PosCustomerDiscounts;
use Modules\Pos\Livewire\PosDamageForm;
use Modules\Pos\Livewire\PosDamages;
use Modules\Pos\Livewire\PosFloorForm;
use Modules\Pos\Livewire\PosFloorPlan;
use Modules\Pos\Livewire\PosFloors;
use Modules\Pos\Livewire\PosHome;
use Modules\Pos\Livewire\PosIngredientForm;
use Modules\Pos\Livewire\PosIngredients;
use Modules\Pos\Livewire\PosOrders;
use Modules\Pos\Livewire\PosProductForm;
use Modules\Pos\Livewire\PosProductImport;
use Modules\Pos\Livewire\PosProducts;
use Modules\Pos\Livewire\PosReporting;
use Modules\Pos\Livewire\ProductionForm;
use Modules\Pos\Livewire\Productions;
use Modules\Pos\Livewire\PosSessionPage;
use Modules\Pos\Livewire\PosStockReport;
use Modules\Pos\Livewire\PosTableForm;
use Modules\Pos\Livewire\PosTables;
use Modules\Pos\Livewire\PosTerminal;

Route::middleware('auth')->group(function (): void {
    Route::get('/app/pos', PosHome::class)->name('pos.home');

    // Reporting dashboard — KPI strip (revenue / orders / AOV) + a
    // preset-scoped orders list. Reached via the 3-dot menu on POS Orders.
    Route::get('/app/pos/reporting', PosReporting::class)->name('pos.reporting');

    // Stock health report (in / low / out of stock) — Odoo-style; the
    // Inventory "Products in stock" KPI card links here. Export/print
    // routes are registered BEFORE the bare report so the suffixes aren't
    // swallowed.
    Route::get('/app/pos/stock-report/export', PosStockReportExportController::class)->name('pos.stock_report.export');
    Route::get('/app/pos/stock-report/print', PosStockReportPrintController::class)->name('pos.stock_report.print');
    Route::get('/app/pos/stock-report', PosStockReport::class)->name('pos.stock_report');

    // Sidebar resource entries (driven by the registered ir_models):
    // pos.order / pos.product / pos.session → these index pages.
    Route::get('/app/pos/order', PosOrders::class)->name('pos.order.index');
    // Browser-printable receipt for a finalised order (Orders list → print).
    Route::get('/app/pos/order/{id}/receipt', PosReceiptPrintController::class)
        ->whereNumber('id')->name('pos.order.receipt');
    Route::get('/app/pos/product', PosProducts::class)->name('pos.product.index');
    Route::get('/app/pos/category', PosCategories::class)->name('pos.category.index');
    Route::get('/app/pos/condiment', PosCondiments::class)->name('pos.condiment.index');
    Route::get('/app/pos/ingredient', PosIngredients::class)->name('pos.ingredient.index');

    // Production & store (mixing) — perfumes POS. The screens re-check the
    // feature flag in mount(), so a stale link 404s when it's off.
    Route::get('/app/pos/production/new', ProductionForm::class)->name('pos.production.create');
    Route::get('/app/pos/production', Productions::class)->name('pos.production.index');
    // Damage / waste log — the bespoke Damage Report (date range + loss totals).
    // Export + new are registered before the bare index so the suffixes aren't
    // swallowed by it.
    Route::get('/app/pos/damage/export', PosDamageReportExportController::class)->name('pos.damage.export');
    Route::get('/app/pos/damage/new', PosDamageForm::class)->name('pos.damage.create');
    Route::get('/app/pos/damage', PosDamages::class)->name('pos.damage.index');
    // Sidebar builds the slug with an underscore (pos.customer_discount →
    // /app/pos/customer_discount), so the route path matches that exactly.
    Route::get('/app/pos/customer_discount', PosCustomerDiscounts::class)->name('pos.customer_discount.index');
    Route::get('/app/pos/session', PosHome::class)->name('pos.session.index');

    // Restaurant floors + tables (admin-managed catalogue; deny-default ACL).
    Route::get('/app/pos/floor', PosFloors::class)->name('pos.floor.index');
    Route::get('/app/pos/floor/new', PosFloorForm::class)->name('pos.floor.create');
    Route::get('/app/pos/floor/{id}', PosFloorForm::class)->whereNumber('id')->name('pos.floor.edit');
    Route::get('/app/pos/table', PosTables::class)->name('pos.table.index');
    Route::get('/app/pos/table/new', PosTableForm::class)->name('pos.table.create');
    Route::get('/app/pos/table/{id}', PosTableForm::class)->whereNumber('id')->name('pos.table.edit');

    Route::get('/app/pos/category/new', PosCategoryForm::class)->name('pos.category.create');
    Route::get('/app/pos/category/{id}', PosCategoryForm::class)
        ->whereNumber('id')->name('pos.category.edit');

    Route::get('/app/pos/condiment/new', PosCondimentForm::class)->name('pos.condiment.create');
    Route::get('/app/pos/condiment/{id}', PosCondimentForm::class)
        ->whereNumber('id')->name('pos.condiment.edit');

    Route::get('/app/pos/ingredient/new', PosIngredientForm::class)->name('pos.ingredient.create');
    Route::get('/app/pos/ingredient/{id}', PosIngredientForm::class)
        ->whereNumber('id')->name('pos.ingredient.edit');

    Route::get('/app/pos/customer_discount/new', PosCustomerDiscountForm::class)->name('pos.customer_discount.create');
    Route::get('/app/pos/customer_discount/{id}', PosCustomerDiscountForm::class)
        ->whereNumber('id')->name('pos.customer_discount.edit');

    // Floor plan — the table picker shown before the terminal.
    Route::get('/app/pos/session/{session}/floor', PosFloorPlan::class)
        ->whereNumber('session')->name('pos.floor');

    // Terminal bound to a specific table (its own running order).
    Route::get('/app/pos/session/{session}/table/{table}', PosTerminal::class)
        ->whereNumber('session')->whereNumber('table')->name('pos.terminal.table');

    // Terminal with no table — walk-in / quick sale.
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
