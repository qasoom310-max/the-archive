<?php

declare(strict_types=1);

namespace Modules\Rental\Providers;

use App\Erp\Notifications\NotificationCenter;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\ServiceProvider;
use Modules\Rental\Support\RentalNotifications;

/**
 * Car-rental module service provider. Loaded by the core ModuleServiceProvider
 * only while the Rental module is installed. Routes and views are auto-loaded
 * by the engine from this module's `routes/` and `resources/views/`, so this
 * provider stays thin (an extension point for future event listeners — e.g.
 * posting rental revenue to Accounting).
 */
final class RentalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Contribute this module's alerts to the top-bar notification bell.
        $this->app->make(NotificationCenter::class)->register(
            fn (Authenticatable $user): array => $this->app->make(RentalNotifications::class)->for($user),
        );
    }
}
