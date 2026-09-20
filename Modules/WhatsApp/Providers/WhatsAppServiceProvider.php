<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\WhatsApp\Assistant\Brain\Brain;
use Modules\WhatsApp\Assistant\Brain\ClaudeBrain;
use Modules\WhatsApp\Assistant\MetaMessenger;
use Modules\WhatsApp\Assistant\NotifyStaffOfPayment;
use Modules\WhatsApp\Assistant\ReplySink;
use Modules\WhatsApp\Services\WhatsAppService;

/**
 * WhatsApp module provider. Loaded by the core ModuleServiceProvider only
 * while installed. Binds the WhatsAppService as a singleton so callers
 * (Chatter button, automated actions) share one configured client, and wires
 * the staff assistant.
 *
 * `ReplySink` binds to the real `MetaMessenger` — the container's default for
 * anywhere the assistant is reached over WhatsApp. The in-ERP test chat
 * doesn't resolve `StaffAssistant` through the container for that reason: it
 * builds one itself with a `WebReplySink` in place of this binding (see
 * {@see \Modules\WhatsApp\Livewire\AssistantChat}).
 */
final class WhatsAppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WhatsAppService::class);
        $this->app->bind(Brain::class, ClaudeBrain::class);
        $this->app->bind(ReplySink::class, MetaMessenger::class);
    }

    public function boot(): void
    {
        // By string name: the Limousine module may not be installed here.
        Event::listen('Modules\Limousine\Events\LimoPaymentLinkPaid', [NotifyStaffOfPayment::class, 'handle']);
    }
}
