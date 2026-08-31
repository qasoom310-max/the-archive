<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Events\PosOrderPaid;
use Modules\Pos\Listeners\SendPosOrderReceiptViaWhatsApp;
use Modules\Pos\Livewire\PosTerminal;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosPaymentMethod;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosSession;
use Modules\Pos\Services\PosReceiptImageRenderer;
use Modules\Pos\Support\PosWhatsAppCountries;
use Modules\WhatsApp\Jobs\SendWhatsAppMessage;
use Modules\WhatsApp\Models\WhatsAppConfiguration;
use Modules\WhatsApp\Models\WhatsAppMessageLog;
use Tests\TestCase;

/**
 * End-to-end auto-receipt flow: cashier types phone → validateOrder fires
 * PosOrderPaid → listener queues a `pos_receipt` template message.
 *
 * The actual Graph HTTP call lives in {@see SendWhatsAppMessage} (queued)
 * and is covered by `WhatsAppModuleTest`; here we verify the wiring
 * stops at "log row created + job dispatched" for each path.
 */
final class PosWhatsAppReceiptTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        // Stub the receipt image renderer so tests don't need Imagick.
        // The real implementation is exercised by its own focused test;
        // here we only care that the listener forwards the returned URL
        // to the WhatsApp service unchanged. Returning a known sentinel
        // makes payload assertions deterministic. Mockery (vs an
        // anonymous subclass) because the renderer is `final` per the
        // project's "final by default" rule.
        $stub = \Mockery::mock(PosReceiptImageRenderer::class);
        $stub->shouldReceive('render')->andReturnUsing(
            fn (\Modules\Pos\Models\PosOrder $order): string =>
                'https://example.test/receipts/' . $order->id . '.png',
        );
        $this->app->instance(PosReceiptImageRenderer::class, $stub);
    }

    private function installModules(): void
    {
        app(ModuleManager::class)->install('pos');
        app(ModuleManager::class)->install('whatsapp');

        // Known engine gap (see project memory `module-routes-need-web-group`):
        // ModuleServiceProvider only boots an installed module's providers at
        // app startup. Tests install mid-run, so PosServiceProvider's boot()
        // — which wires the auto-receipt listener — never fires. We mirror
        // that registration here so the test exercises the real production
        // wiring rather than calling the listener directly.
        Event::listen(PosOrderPaid::class, [SendPosOrderReceiptViaWhatsApp::class, 'handle']);
    }

    private function configureWhatsApp(string $templateLanguage = 'en'): void
    {
        WhatsAppConfiguration::query()->create([
            'phone_number_id' => '100000000000001',
            'business_account_id' => '200000000000002',
            'access_token' => 'EAAG-secret-token',
            'app_secret' => 'app-secret-abc',
            'webhook_verify_token' => 'verify-me-123',
            'api_version' => 'v21.0',
            'template_language' => $templateLanguage,
            'enabled' => true,
        ]);
    }

    private function openSession(): PosSession
    {
        return PosSession::query()->create([
            'reference' => 'POS-S/0001',
            'state' => SessionState::Opened,
            'opening_cash' => 50.0,
            'opened_at' => now(),
        ]);
    }

    private function seedCashPayment(): PosPaymentMethod
    {
        return PosPaymentMethod::query()->create([
            'name' => 'Cash',
            'kind' => 'cash',
            'active' => true,
            'sequence' => 1,
        ]);
    }

    public function test_compose_strips_leading_zero_and_returns_meta_ready_digits(): void
    {
        $this->assertSame('97333123456', PosWhatsAppCountries::compose('+973', '33123456'));
        $this->assertSame('97333123456', PosWhatsAppCountries::compose('+973', '0 33 123 456'));
        $this->assertSame('201005550000', PosWhatsAppCountries::compose('+20', '01005550000'));
        $this->assertNull(PosWhatsAppCountries::compose('+973', ''));
        $this->assertNull(PosWhatsAppCountries::compose('+973', '0')); // local is just the trunk-prefix
    }

    public function test_compose_rejects_an_unknown_dial_code(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PosWhatsAppCountries::compose('+99', '12345');
    }

    public function test_finalize_with_phone_queues_a_pos_receipt_template_message(): void
    {
        $this->installModules();
        $this->configureWhatsApp();
        Bus::fake();

        $session = $this->openSession();
        $method = $this->seedCashPayment();
        $product = PosProduct::query()->create(['name' => 'Coffee', 'price' => 10.0, 'tax_rate' => 0]);

        $c = Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('addProduct', $product->id)
            ->call('startPayment')
            ->set('countryCode', '+973')
            ->set('localPhone', '33 123 456')
            ->set('paymentMethodId', $method->id)
            ->set('tendered', '10.00')
            ->call('addPayment')
            ->call('validateOrder');

        $order = PosOrder::query()->where('pos_session_id', $session->id)->sole();
        $this->assertSame(OrderState::Done, $order->state);
        $this->assertSame('97333123456', $order->customer_phone);

        // Exactly one queued WhatsApp log row, addressed to the composed number
        // with the agreed `pos_receipt` template.
        $log = WhatsAppMessageLog::query()
            ->where('direction', 'outbound')
            ->where('template_name', 'pos_receipt')
            ->sole();
        $this->assertSame('97333123456', $log->contact_number);
        $this->assertSame('queued', $log->status);

        // 4 ordered placeholders per the spec: name, ref, total+currency, datetime.
        $payload = $log->payload;
        $this->assertIsArray($payload);
        $params = $payload['template']['components'][1]['parameters'] ?? null;
        $this->assertIsArray($params);
        // 4 positional placeholders: store, ref, total, datetime
        // (customer-name slot was dropped — cashiers rarely capture a
        // partner, so "Hello Walk-in" was noise on every receipt).
        $this->assertCount(4, $params);
        $this->assertIsString($params[0]['text']);                   // store name from company.name
        $this->assertSame($order->reference, $params[1]['text']);
        $this->assertStringContainsString('10.00', $params[2]['text']);

        // And the queued HTTP-call job was actually dispatched.
        Bus::assertDispatched(SendWhatsAppMessage::class, fn (SendWhatsAppMessage $job): bool => $job->to === '97333123456' && $job->logId === $log->id,
        );
    }

    public function test_template_language_code_is_read_from_configuration(): void
    {
        // Pre-fix the listener hard-coded 'en_US'. Meta rejected with
        // #132001 "Template name does not exist in the translation" when
        // the customer's account had `pos_receipt` approved under 'en'.
        // Reading the language from `WhatsAppConfiguration::template_language`
        // lets admins flip 'en' / 'en_US' / 'ar' from the Settings UI.
        $this->installModules();
        $this->configureWhatsApp(templateLanguage: 'ar');
        Bus::fake();

        $session = $this->openSession();
        $method = $this->seedCashPayment();
        $product = PosProduct::query()->create(['name' => 'Tea', 'price' => 2.0, 'tax_rate' => 0]);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('addProduct', $product->id)
            ->call('startPayment')
            ->set('countryCode', '+973')
            ->set('localPhone', '33000000')
            ->set('paymentMethodId', $method->id)
            ->set('tendered', '2.00')
            ->call('addPayment')
            ->call('validateOrder');

        $log = WhatsAppMessageLog::query()
            ->where('direction', 'outbound')
            ->where('template_name', 'pos_receipt')
            ->sole();

        $payload = $log->payload;
        $this->assertIsArray($payload);
        $this->assertSame('ar', $payload['template']['language']['code'] ?? null);
    }

    public function test_template_language_defaults_to_en_when_unset(): void
    {
        // Fallback for an old config row that predates the column being
        // added. The migration sets default 'en', so callers don't need
        // a config flip on existing installs — but the listener also
        // null-coalesces to 'en' as belt-and-braces.
        $this->installModules();
        $this->configureWhatsApp(templateLanguage: '');
        Bus::fake();

        $session = $this->openSession();
        $method = $this->seedCashPayment();
        $product = PosProduct::query()->create(['name' => 'Cookie', 'price' => 1.0, 'tax_rate' => 0]);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('addProduct', $product->id)
            ->call('startPayment')
            ->set('countryCode', '+973')
            ->set('localPhone', '33111111')
            ->set('paymentMethodId', $method->id)
            ->set('tendered', '1.00')
            ->call('addPayment')
            ->call('validateOrder');

        $log = WhatsAppMessageLog::query()
            ->where('direction', 'outbound')
            ->where('template_name', 'pos_receipt')
            ->sole();

        $payload = $log->payload;
        $this->assertIsArray($payload);
        $this->assertSame('en', $payload['template']['language']['code'] ?? null);
    }

    public function test_payload_includes_a_header_image_component_with_renderer_url(): void
    {
        // The listener calls PosReceiptImageRenderer to produce a public
        // image URL, then passes it through WhatsAppService as a
        // HEADER:IMAGE component. Meta needs the components in the
        // header-then-body order — verify the shape here so a future
        // refactor can't drop the header silently.
        $this->installModules();
        $this->configureWhatsApp();
        Bus::fake();

        $session = $this->openSession();
        $method = $this->seedCashPayment();
        $product = PosProduct::query()->create(['name' => 'Espresso', 'price' => 3.0, 'tax_rate' => 0]);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('addProduct', $product->id)
            ->call('startPayment')
            ->set('countryCode', '+973')
            ->set('localPhone', '33000000')
            ->set('paymentMethodId', $method->id)
            ->set('tendered', '3.00')
            ->call('addPayment')
            ->call('validateOrder');

        $log = WhatsAppMessageLog::query()
            ->where('direction', 'outbound')
            ->where('template_name', 'pos_receipt')
            ->sole();

        $payload = $log->payload;
        $this->assertIsArray($payload);
        $components = $payload['template']['components'];
        $this->assertSame('header', $components[0]['type']);
        $this->assertSame('image', $components[0]['parameters'][0]['type']);
        $this->assertStringStartsWith('https://example.test/receipts/', $components[0]['parameters'][0]['image']['link']);
        $this->assertSame('body', $components[1]['type']);
    }

    public function test_store_name_variable_is_company_name_setting(): void
    {
        // The 2nd template parameter ({{2}}) holds the store / brand name
        // pulled from `company.name` — same source the on-screen receipt
        // header uses. Pins both the value AND the position so a future
        // reorder can't silently desync from the Meta-approved template.
        $this->installModules();
        $this->configureWhatsApp();
        \App\Erp\Settings\Setting::set('company.name', 'Sweileh Cafe');
        Bus::fake();

        $session = $this->openSession();
        $method = $this->seedCashPayment();
        $product = PosProduct::query()->create(['name' => 'Espresso', 'price' => 3.0, 'tax_rate' => 0]);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('addProduct', $product->id)
            ->call('startPayment')
            ->set('countryCode', '+973')
            ->set('localPhone', '33000000')
            ->set('paymentMethodId', $method->id)
            ->set('tendered', '3.00')
            ->call('addPayment')
            ->call('validateOrder');

        $log = WhatsAppMessageLog::query()
            ->where('direction', 'outbound')
            ->where('template_name', 'pos_receipt')
            ->sole();

        $payload = $log->payload;
        $this->assertIsArray($payload);
        $params = $payload['template']['components'][1]['parameters'];
        // Store name is now {{1}} (index 0) after the customer-name slot
        // was dropped — it's the first thing in the body.
        $this->assertSame('Sweileh Cafe', $params[0]['text']);
    }

    public function test_pos_receipt_template_variable_uses_12_hour_clock(): void
    {
        // The 4th positional template variable is the order datetime —
        // every retail POS in the region prints "6:27 PM" rather than
        // "18:27", and the WhatsApp message has to match the on-screen
        // receipt. Pinning the formatter here so a refactor doesn't
        // silently flip it back to 24-hour.
        $this->installModules();
        $this->configureWhatsApp();
        Bus::fake();

        $session = $this->openSession();
        $method = $this->seedCashPayment();
        $product = PosProduct::query()->create(['name' => 'Coffee', 'price' => 4.0, 'tax_rate' => 0]);

        // Lock the clock to an afternoon moment so the 12-hour formatter
        // produces a "PM" suffix (06:27 → "6:27 AM" would also work; we
        // want a known suffix to assert on).
        \Illuminate\Support\Carbon::setTestNow('2026-05-24 18:27:00');

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('addProduct', $product->id)
            ->call('startPayment')
            ->set('countryCode', '+973')
            ->set('localPhone', '33 123 456')
            ->set('paymentMethodId', $method->id)
            ->set('tendered', '4.00')
            ->call('addPayment')
            ->call('validateOrder');

        $log = WhatsAppMessageLog::query()
            ->where('direction', 'outbound')
            ->where('template_name', 'pos_receipt')
            ->sole();

        $payload = $log->payload;
        $this->assertIsArray($payload);
        $params = $payload['template']['components'][1]['parameters'] ?? null;
        $this->assertIsArray($params);

        // 4 positional vars now: store, ref, total, datetime — datetime
        // is index 3 after dropping the customer-name slot.
        $orderedAt = $params[3]['text'];
        // Day/month/year, as dates are written here.
        $this->assertSame('24-05-2026 6:27 PM', $orderedAt);

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_finalize_without_phone_sends_no_receipt(): void
    {
        $this->installModules();
        $this->configureWhatsApp();
        Bus::fake();

        $session = $this->openSession();
        $method = $this->seedCashPayment();
        $product = PosProduct::query()->create(['name' => 'Coffee', 'price' => 5.0, 'tax_rate' => 0]);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('addProduct', $product->id)
            ->call('startPayment')
            ->set('paymentMethodId', $method->id)
            ->set('tendered', '5.00')
            ->call('addPayment')
            ->call('validateOrder');

        $order = PosOrder::query()->where('pos_session_id', $session->id)->sole();
        $this->assertSame(OrderState::Done, $order->state);
        $this->assertNull($order->customer_phone);

        $this->assertSame(0, WhatsAppMessageLog::query()->where('direction', 'outbound')->count());
        Bus::assertNotDispatched(SendWhatsAppMessage::class);
    }

    public function test_finalize_when_whatsapp_disabled_does_not_break_the_sale(): void
    {
        $this->installModules();
        // Note: NOT configuring WhatsApp — sendTemplateMessage will throw
        // WhatsAppException, which the listener must catch.
        Bus::fake();

        $session = $this->openSession();
        $method = $this->seedCashPayment();
        $product = PosProduct::query()->create(['name' => 'Coffee', 'price' => 7.5, 'tax_rate' => 0]);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('addProduct', $product->id)
            ->call('startPayment')
            ->set('countryCode', '+973')
            ->set('localPhone', '33999999')
            ->set('paymentMethodId', $method->id)
            ->set('tendered', '7.50')
            ->call('addPayment')
            ->call('validateOrder');

        // Sale still completes cleanly even though WhatsApp is unconfigured.
        $order = PosOrder::query()->where('pos_session_id', $session->id)->sole();
        $this->assertSame(OrderState::Done, $order->state);
        $this->assertSame('97333999999', $order->customer_phone);

        // No WhatsApp log row created because the service threw before
        // it could write one.
        $this->assertSame(0, WhatsAppMessageLog::query()->count());
        Bus::assertNotDispatched(SendWhatsAppMessage::class);
    }
}
