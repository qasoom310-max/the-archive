<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Pos\Http\Controllers\PosProductImportTemplateController;
use Modules\Pos\Livewire\PosCategories;
use Modules\Pos\Livewire\PosCategoryForm;
use Modules\Pos\Livewire\PosHome;
use Modules\Pos\Livewire\PosOrders;
use Modules\Pos\Livewire\PosProductForm;
use Modules\Pos\Livewire\PosProductImport;
use Modules\Pos\Livewire\PosProducts;
use Modules\Pos\Livewire\PosSessionPage;
use Modules\Pos\Livewire\PosTerminal;

Route::middleware('auth')->group(function (): void {
    Route::get('/app/pos', PosHome::class)->name('pos.home');

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

    // Bulk import — MUST register before the /{id} wildcard so 'import' isn't
    // captured as a numeric id. The template route is even more specific.
    Route::get('/app/pos/product/import/template', PosProductImportTemplateController::class)
        ->name('pos.product.import.template');
    Route::get('/app/pos/product/import', PosProductImport::class)->name('pos.product.import');

    Route::get('/app/pos/product/{id}', PosProductForm::class)
        ->whereNumber('id')->name('pos.product.edit');
});
