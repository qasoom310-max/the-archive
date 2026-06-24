<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\WooCommerce\Livewire\WooCommerceSettings;

Route::middleware('auth')->group(function (): void {
    // Two-segment path: not shadowed by the core `/app/{module}` wildcard.
    Route::get('/app/settings/woocommerce', WooCommerceSettings::class)
        ->name('woocommerce.settings');
});
