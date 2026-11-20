<?php

declare(strict_types=1);

namespace Modules\Storefront\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Online store module. Nothing runs in the background: the website pulls the
 * catalog when it wants it and pushes orders when they happen, so a store
 * that is switched off costs nothing.
 */
final class StorefrontServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
    }
}
