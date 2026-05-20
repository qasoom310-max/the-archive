<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Enums\ModuleState;
use App\Erp\Modules\ModuleManager;
use App\Models\Ir\IrModule;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Modules\WhatsApp\Events\IncomingWhatsAppMessage;
use Modules\WhatsApp\Http\Controllers\WhatsAppWebhookController;
use Modules\WhatsApp\Jobs\SendWhatsAppMessage;
use Modules\WhatsApp\Livewire\WhatsAppSettings;
use Modules\WhatsApp\Models\WhatsAppConfiguration;
use Modules\WhatsApp\Models\WhatsAppMessageLog;
use Modules\WhatsApp\Services\WhatsAppService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class WhatsAppModuleTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function installWhatsApp(): void
    {
        app(ModuleManager::class)->install('whatsapp');
    }

    private function configure(): WhatsAppConfiguration
    {
        return WhatsAppConfiguration::query()->create([
            'phone_number_id' => '100000000000001',
            'business_account_id' => '200000000000002',
            'access_token' => 'EAAG-secret-token',
            'app_secret' => 'app-secret-abc',
            'webhook_verify_token' => 'verify-me-123',
            'api_version' => 'v21.0',
            'enabled' => true,
        ]);
    }

    public function test_install_creates_whatsapp_schema(): void
    {
        $this->installWhatsApp();

        $this->assertSame(
            ModuleState::Installed,
            IrModule::query()->where('name', 'whatsapp')->sole()->state,
        );
        $this->assertTrue(Schema::hasTable('whatsapp_configuration'));
        $this->assertTrue(Schema::hasTable('whatsapp_messages_log'));
    }

    public function test_admin_can_save_configuration_and_secrets_are_encrypted_at_rest(): void
    {
        $this->installWhatsApp();

        Livewire::test(WhatsAppSettings::class)
            ->set('phoneNumberId', '100000000000001')
            ->set('accessToken', 'SUPER-SECRET-TOKEN')
            ->set('appSecret', 'APP-SECRET-XYZ')
            ->set('webhookVerifyToken', 'VERIFY-TOKEN')
            ->set('enabled', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true)
            ->assertSet('accessToken', ''); // cleared after save

        $config = WhatsAppConfiguration::current();
        $this->assertTrue($config->isConfigured());
        $this->assertSame('SUPER-SECRET-TOKEN', $config->access_token);

        // Stored ciphertext must not be the plaintext.
        $rawToken = DB::table('whatsapp_configuration')->value('access_token');
        $this->assertIsString($rawToken);
        $this->assertNotSame('SUPER-SECRET-TOKEN', $rawToken);
    }

    public function test_enabling_without_credentials_is_rejected(): void
    {
        $this->installWhatsApp();

        Livewire::test(WhatsAppSettings::class)
            ->set('enabled', true)
            ->set('phoneNumberId', '')
            ->call('save')
            ->assertHasErrors('enabled')
            ->assertSet('saved', false);
    }

    public function test_non_admin_cannot_open_whatsapp_settings(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        Livewire::test(WhatsAppSettings::class)->assertForbidden();
    }

    public function test_service_queues_job_and_writes_a_queued_log_row(): void
    {
        $this->installWhatsApp();
        $this->configure();
        Bus::fake();

        app(WhatsAppService::class)->sendTemplateMessage(
            '+20 100 555 0000',
            'order_confirmation',
            ['Ahmed', '#A-1007'],
        );

        Bus::assertDispatched(SendWhatsAppMessage::class, function (SendWhatsAppMessage $job): bool {
            return $job->to === '201005550000' && $job->logId !== null;
        });

        $this->assertDatabaseHas('whatsapp_messages_log', [
            'direction' => 'outbound',
            'status' => 'queued',
            'template_name' => 'order_confirmation',
            'contact_number' => '201005550000',
        ]);
    }

    public function test_unconfigured_service_throws(): void
    {
        $this->installWhatsApp();

        $this->expectException(\Modules\WhatsApp\Exceptions\WhatsAppException::class);

        app(WhatsAppService::class)->sendTemplateMessage('201005550000', 'whatever');
    }

    public function test_job_marks_log_sent_with_wamid_on_success(): void
    {
        $this->installWhatsApp();
        $this->configure();

        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.SENT.1']]], 200),
        ]);

        $log = WhatsAppMessageLog::query()->create([
            'direction' => 'outbound', 'contact_number' => '201005550000',
            'message_type' => 'template', 'status' => 'queued',
        ]);

        (new SendWhatsAppMessage('201005550000', ['x' => 1], $log->id))
            ->handle(app(HttpFactory::class));

        $log->refresh();
        $this->assertSame('sent', $log->status);
        $this->assertSame('wamid.SENT.1', $log->wamid);
    }

    public function test_job_marks_log_failed_on_api_error(): void
    {
        $this->installWhatsApp();
        $this->configure();

        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => 'bad'], 400),
        ]);

        $log = WhatsAppMessageLog::query()->create([
            'direction' => 'outbound', 'contact_number' => '201005550000',
            'message_type' => 'template', 'status' => 'queued',
        ]);

        try {
            (new SendWhatsAppMessage('201005550000', ['x' => 1], $log->id))
                ->handle(app(HttpFactory::class));
            $this->fail('Expected WhatsAppException.');
        } catch (\Modules\WhatsApp\Exceptions\WhatsAppException) {
            // expected
        }

        $log->refresh();
        $this->assertSame('failed', $log->status);
        $this->assertNotNull($log->error);
    }

    public function test_webhook_get_verifies_with_correct_token_and_rejects_wrong_one(): void
    {
        $this->installWhatsApp();
        $this->configure();
        $controller = new WhatsAppWebhookController();

        $ok = $controller->verify(Request::create('/whatsapp/webhook', 'GET', [
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'verify-me-123',
            'hub.challenge' => 'CHALLENGE-XYZ',
        ]));
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertSame('CHALLENGE-XYZ', $ok->getContent());

        try {
            $controller->verify(Request::create('/whatsapp/webhook', 'GET', [
                'hub.mode' => 'subscribe',
                'hub.verify_token' => 'WRONG',
                'hub.challenge' => 'X',
            ]));
            $this->fail('Expected 403.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_webhook_post_rejects_a_bad_signature(): void
    {
        $this->installWhatsApp();
        $this->configure();

        $raw = json_encode(['object' => 'whatsapp_business_account', 'entry' => []]);
        $this->assertIsString($raw);

        $request = Request::create('/whatsapp/webhook', 'POST', [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=deadbeef',
            'CONTENT_TYPE' => 'application/json',
        ], $raw);

        try {
            (new WhatsAppWebhookController())->handle($request);
            $this->fail('Expected 403.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_webhook_post_updates_outbound_status_and_stores_inbound(): void
    {
        $this->installWhatsApp();
        $this->configure();

        WhatsAppMessageLog::query()->create([
            'wamid' => 'wamid.OUT.1', 'direction' => 'outbound',
            'contact_number' => '201005550000', 'status' => 'sent',
        ]);

        $body = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'statuses' => [[
                            'id' => 'wamid.OUT.1', 'status' => 'delivered',
                            'recipient_id' => '201005550000',
                        ]],
                        'messages' => [[
                            'from' => '201112223333', 'id' => 'wamid.IN.9',
                            'type' => 'text', 'text' => ['body' => 'Hello back'],
                        ]],
                    ],
                ]],
            ]],
        ];

        $raw = json_encode($body);
        $this->assertIsString($raw);
        $sig = 'sha256=' . hash_hmac('sha256', $raw, 'app-secret-abc');

        $request = Request::create('/whatsapp/webhook', 'POST', [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ], $raw);

        $response = (new WhatsAppWebhookController())->handle($request);
        $this->assertSame(200, $response->getStatusCode());

        $this->assertSame('delivered', WhatsAppMessageLog::query()
            ->where('wamid', 'wamid.OUT.1')->sole()->status);

        $inbound = WhatsAppMessageLog::query()
            ->where('direction', 'inbound')->sole();
        $this->assertSame('201112223333', $inbound->contact_number);
        $this->assertSame('received', $inbound->status);
        $this->assertSame('wamid.IN.9', $inbound->wamid);
    }

    public function test_webhook_post_dispatches_incoming_message_event(): void
    {
        $this->installWhatsApp();
        $this->configure();
        Event::fake([IncomingWhatsAppMessage::class]);

        $body = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => [
                            'display_phone_number' => '15551234567',
                            'phone_number_id' => 'PNID-1',
                        ],
                        'messages' => [[
                            'from' => '201112223333', 'id' => 'wamid.IN.42',
                            'type' => 'text', 'text' => ['body' => 'Ping'],
                        ]],
                    ],
                ]],
            ]],
        ];
        $raw = (string) json_encode($body);
        $sig = 'sha256=' . hash_hmac('sha256', $raw, 'app-secret-abc');

        $request = Request::create('/whatsapp/webhook', 'POST', [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ], $raw);

        (new WhatsAppWebhookController())->handle($request);

        // One event per inbound message, with metadata + identifying fields.
        Event::assertDispatched(IncomingWhatsAppMessage::class, function (IncomingWhatsAppMessage $e): bool {
            return $e->wamid === 'wamid.IN.42'
                && $e->from === '201112223333'
                && $e->type === 'text'
                && ($e->metadata['phone_number_id'] ?? null) === 'PNID-1';
        });
    }

    public function test_webhook_tees_verified_payload_to_dedicated_log_channel(): void
    {
        $this->installWhatsApp();
        $this->configure();

        // Spy on the `whatsapp` channel to verify the controller logs to it
        // (and not the default channel). Asserts the routing — actual file
        // I/O is Laravel's concern, already tested by Laravel itself.
        Log::shouldReceive('channel')->with('whatsapp')->andReturnSelf();
        Log::shouldReceive('info')->once()
            ->withArgs(function (string $msg, array $ctx): bool {
                return $msg === 'Webhook payload received'
                    && isset($ctx['payload']);
            });

        $body = ['object' => 'whatsapp_business_account', 'entry' => []];
        $raw = (string) json_encode($body);
        $sig = 'sha256=' . hash_hmac('sha256', $raw, 'app-secret-abc');

        $request = Request::create('/whatsapp/webhook', 'POST', [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ], $raw);

        (new WhatsAppWebhookController())->handle($request);
    }
}
