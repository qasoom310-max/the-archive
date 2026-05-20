<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Livewire\InventoryOverview;
use Modules\Inventory\Livewire\StockTransferForm;
use Modules\Inventory\Livewire\StockTransfers;

Route::middleware('auth')->group(function (): void {
    Route::get('/app/inventory', InventoryOverview::class)->name('inventory.overview');

    // Order matters: /new before the list so it isn't shadowed.
    Route::get('/app/inventory/transfers/new', StockTransferForm::class)->name('inventory.transfer.create');
    Route::get('/app/inventory/transfers', StockTransfers::class)->name('inventory.transfers');
});
