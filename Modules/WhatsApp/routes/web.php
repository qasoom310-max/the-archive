<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\WhatsApp\Http\Controllers\AssistantChatDownloadController;
use Modules\WhatsApp\Http\Controllers\AssistantWebhookController;
use Modules\WhatsApp\Http\Controllers\WhatsAppWebhookController;
use Modules\WhatsApp\Livewire\AssistantChat;
use Modules\WhatsApp\Livewire\AssistantSettings;
use Modules\WhatsApp\Livewire\WhatsAppSettings;

// Public, server-to-server (Meta). NOT in the `auth` group; CSRF for the
// POST is excepted in bootstrap/app.php. Security is enforced in the
// controller (verify-token handshake + X-Hub-Signature-256 HMAC).
Route::get('/whatsapp/webhook', [WhatsAppWebhookController::class, 'verify'])
    ->name('whatsapp.webhook.verify');
Route::post('/whatsapp/webhook', [WhatsAppWebhookController::class, 'handle'])
    ->name('whatsapp.webhook.handle');

// The staff assistant's webhook — one URL per database, because the Meta
// secrets are per database. Public + CSRF-exempt (integrations/whatsapp/*);
// the controller checks the verify token / X-Hub-Signature-256 against THAT
// database's settings.
Route::get('/integrations/whatsapp/{workspace}/webhook', [AssistantWebhookController::class, 'verify'])
    ->whereNumber('workspace')->name('whatsapp.assistant.verify');
Route::post('/integrations/whatsapp/{workspace}/webhook', [AssistantWebhookController::class, 'handle'])
    ->whereNumber('workspace')->middleware('throttle:240,1')->name('whatsapp.assistant.handle');

Route::middleware('auth')->group(function (): void {
    // Two-segment path: not shadowed by the core `/app/{module}` wildcard.
    Route::get('/app/settings/whatsapp', WhatsAppSettings::class)
        ->name('whatsapp.settings');
    Route::get('/app/settings/whatsapp-assistant', AssistantSettings::class)
        ->name('whatsapp.assistant.settings');
    // Not admin-only — the component itself allows an admin OR a mapped
    // staff number, so the page-load gate has to match the component's own.
    Route::get('/app/settings/whatsapp-assistant/chat', AssistantChat::class)
        ->name('whatsapp.assistant.chat');
    Route::get('/app/settings/whatsapp-assistant/chat/download', AssistantChatDownloadController::class)
        ->name('whatsapp.assistant.chat.download');
});
