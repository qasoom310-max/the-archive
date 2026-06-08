<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Purchases\Livewire\PurchaseForm;
use Modules\Purchases\Livewire\Purchases;

/*
 * Every `DefinesIrModel` in this module needs an index route here — the
 * engine reads `ir_model` for the sidebar but does NOT auto-mount routes.
 * The form is a CUSTOM line-editor (master/detail), not the generic engine
 * FormView. (memory: module-sidebar-and-engine-contract)
 */
Route::middleware('auth')->group(function (): void {
    Route::redirect('/app/purchases', '/app/purchases/purchase');

    Route::get('/app/purchases/purchase', Purchases::class)
        ->name('purchases.purchase.index');
    Route::get('/app/purchases/purchase/new', PurchaseForm::class)
        ->name('purchases.purchase.create');
    Route::get('/app/purchases/purchase/{id}', PurchaseForm::class)
        ->whereNumber('id')
        ->name('purchases.purchase.edit');
});
