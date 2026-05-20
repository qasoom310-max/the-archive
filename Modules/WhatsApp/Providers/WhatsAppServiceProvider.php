<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\WhatsApp\Services\WhatsAppService;

/**
 * WhatsApp module provider. Loaded by the core ModuleServiceProvider only
 * while installed. Binds the WhatsAppService as a singleton so callers
 * (Chatter button, automated actions) share one configured client.
 */
final class WhatsAppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WhatsAppService::class);
    }

    public function boot(): void
    {
        //
    }
}
