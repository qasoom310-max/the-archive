<?php

declare(strict_types=1);

namespace Modules\Contacts\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Module service provider. Loaded by the core ModuleServiceProvider only
 * while the Contacts module is installed. Routes and views are auto-loaded
 * by the engine from this module's `routes/` and `resources/views/`
 * directories, so this provider stays intentionally thin (extension point).
 */
final class ContactsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
