<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Storefront\Http\Controllers\StorefrontApiController;
use Modules\Storefront\Livewire\StorefrontOrders;
use Modules\Storefront\Livewire\StorefrontProducts;
use Modules\Storefront\Livewire\StorefrontSettings;

/*
| The shop website (a separate site) talks to these. Public, outside `auth`,
| CSRF-excepted in bootstrap/app.php — the HMAC signature is the only
| credential (StoreSignature). {ws} is the business database, 0 for Main.
*/
Route::prefix('storefront/v1/{ws}')->whereNumber('ws')->group(function (): void {
    Route::get('/catalog', [StorefrontApiController::class, 'catalog'])->middleware('throttle:120,1');
    Route::post('/orders', [StorefrontApiController::class, 'placeOrder'])->middleware('throttle:30,1');
    Route::get('/orders/{reference}', [StorefrontApiController::class, 'orderStatus'])
        ->where('reference', '[A-Za-z0-9_-]{1,64}')
        ->middleware('throttle:120,1');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/app/settings/online-store', StorefrontSettings::class)->name('storefront.settings');
    Route::get('/app/pos/website-orders', StorefrontOrders::class)->name('storefront.orders');
    Route::get('/app/pos/website-products', StorefrontProducts::class)->name('storefront.products');
});
