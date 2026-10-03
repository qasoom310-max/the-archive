<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Limousine\Http\Controllers\PaymentCallbackController;
use Modules\Limousine\Livewire\Bookings;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoPaymentLink;
use Modules\Limousine\Models\LimoPortalConfiguration;
use Modules\Limousine\Models\LimoReceipt;
use Modules\Limousine\Services\ServiceOrderPortalClient;
use Modules\Limousine\Support\PortalSignature;
use Tests\TestCase;

/**
 * The Wanaan WordPress service-order portal: pushing a payment link out and
 * settling the booking when the paid-callback comes back.
 */
final class LimoServiceOrderPortalTest extends TestCase
{
    use DatabaseMigrations;

    private const SECRET = 'shhh-super-secret-key';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    /** One booking + one priced leg. */
    private function trip(float $fare = 45.0): LimoLeg
    {
        $customer = LimoCustomer::query()->create(['name' => 'Qassim Makhlooq', 'phone' => '38467744', 'email' => 'qassim@example.com']);

        $booking = LimoBooking::query()->create([
            'reference' => 'BK/00005',
            'customer_id' => $customer->id,
            'pax_name' => 'Qassim Makhlooq',
            'pax_contact' => '38467744',
            'pickup_at' => now()->addDay(),
            'status' => LimoBooking::STATUS_QUEUE,
        ]);

        $leg = LimoLeg::query()->create([
            'legable_type' => LimoBooking::class,
            'legable_id' => $booking->id,
            'sequence' => 0,
            'reference' => '10007',
            'status' => LimoLeg::STATUS_QUEUE,
            'start_at' => now()->addDay(),
            'from_location' => 'Home',
            'to_location' => 'Dammam Airport',
            'rate' => $fare, 'net_amount' => $fare,
        ]);

        $booking->recalcTotal();
        $booking->save();

        return $leg->refresh();
    }

    private function enablePortal(): void
    {
        $config = LimoPortalConfiguration::current();
        $config->portal_url = 'https://wanaan-bh.com';
        $config->shared_secret = self::SECRET;
        $config->enabled = true;
        $config->save();
    }

    private function link(LimoLeg $leg, float $amount = 45.0): LimoPaymentLink
    {
        return LimoPaymentLink::query()->create([
            'leg_id' => $leg->id,
            'booking_id' => $leg->legable_id,
            'amount' => $amount,
        ]);
    }

    public function test_the_portal_is_off_by_default_and_never_pushes(): void
    {
        Http::fake();
        $leg = $this->trip();

        $this->assertFalse(LimoPortalConfiguration::current()->isConfigured());
        $this->assertFalse(app(ServiceOrderPortalClient::class)->push($this->link($leg)));

        Http::assertNothingSent();
    }

    public function test_push_sends_a_signed_service_order_and_stores_the_returned_link(): void
    {
        $this->enablePortal();
        $leg = $this->trip(45.0);
        $link = $this->link($leg, 45.0);

        Http::fake([
            '*/wp-json/wanaan/v1/booking' => Http::response([
                'url' => 'https://wanaan-bh.com/service-order/abc123',
                'token' => 'abc123',
            ], 200),
        ]);

        $this->assertTrue(app(ServiceOrderPortalClient::class)->push($link));

        $link->refresh();
        $this->assertSame('https://wanaan-bh.com/service-order/abc123', $link->url);
        $this->assertSame('abc123', $link->token);

        Http::assertSent(function ($request): bool {
            $body = $request->body();
            $data = json_decode($body, true);

            // The signature the ERP sent verifies against the shared secret.
            $signed = PortalSignature::verify(
                $body,
                $request->header(PortalSignature::TIMESTAMP_HEADER)[0] ?? '',
                $request->header(PortalSignature::SIGNATURE_HEADER)[0] ?? '',
                self::SECRET,
            );

            return $signed
                && $data['confirmation_no'] === '10007'
                && $data['booking_no'] === 'BK/00005'
                && $data['pickup'] === 'Home'
                && $data['dropoff'] === 'Dammam Airport'
                && $data['email'] === 'qassim@example.com'
                // BHD crosses the wire as an exact 3-decimal string.
                && $data['amount'] === '45.000'
                && $data['currency'] === 'BHD';
        });
    }

    public function test_the_callback_settles_the_booking_and_marks_the_link_paid(): void
    {
        $this->enablePortal();
        $leg = $this->trip(45.0);
        $link = $this->link($leg, 45.0);

        $this->assertSame(LimoBooking::PAYMENT_UNPAID, $leg->legable->fresh()->payment_status);

        $response = $this->postCallback([
            'ws' => null,
            'erp_payment_id' => $link->id,
            'erp_booking_id' => $link->booking_id,
            'status' => 'paid',
            'amount' => '45.000',
            'currency' => 'BHD',
            'woo_order_id' => 812,
            'transaction_ref' => 'tap_chg_9f',
        ]);

        $this->assertSame(200, $response->getStatusCode());

        $booking = LimoBooking::query()->find($link->booking_id);
        $this->assertNotNull($booking);
        $this->assertSame(LimoBooking::PAYMENT_PAID, $booking->payment_status);
        $this->assertEqualsWithDelta(45.0, (float) $booking->advance, 0.0001);

        $link->refresh();
        $this->assertSame(LimoPaymentLink::STATUS_PAID, $link->status);
        $this->assertSame(812, $link->woo_order_id);
        $this->assertSame('tap_chg_9f', $link->transaction_ref);
        $this->assertNotNull($link->paid_at);

        // A receipt was issued for the online payment.
        $this->assertSame(1, LimoReceipt::query()->where('booking_id', $booking->id)->count());
    }

    public function test_the_callback_rejects_a_bad_signature(): void
    {
        $this->enablePortal();
        $leg = $this->trip(45.0);
        $link = $this->link($leg, 45.0);

        $response = $this->postCallback(
            ['ws' => null, 'erp_payment_id' => $link->id, 'transaction_ref' => 'x'],
            signature: 'deadbeef',
        );

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(LimoPaymentLink::STATUS_UNPAID, $link->fresh()->status);
        $this->assertSame(LimoBooking::PAYMENT_UNPAID, $leg->legable->fresh()->payment_status);
    }

    public function test_the_callback_is_idempotent(): void
    {
        $this->enablePortal();
        $leg = $this->trip(45.0);
        $link = $this->link($leg, 45.0);

        $payload = [
            'ws' => null,
            'erp_payment_id' => $link->id,
            'erp_booking_id' => $link->booking_id,
            'transaction_ref' => 'tap_chg_9f',
        ];

        $this->assertSame(200, $this->postCallback($payload)->getStatusCode());
        $this->assertSame(200, $this->postCallback($payload)->getStatusCode());

        // Credited once, not twice.
        $this->assertEqualsWithDelta(45.0, (float) LimoBooking::query()->find($link->booking_id)->advance, 0.0001);
        $this->assertSame(1, LimoReceipt::query()->where('booking_id', $link->booking_id)->count());
    }

    public function test_an_agent_can_create_a_payment_link_from_the_trip(): void
    {
        $this->enablePortal();
        $leg = $this->trip(45.0);

        Http::fake([
            '*/wp-json/wanaan/v1/booking' => Http::response([
                'url' => 'https://wanaan-bh.com/service-order/tok999',
                'token' => 'tok999',
            ], 200),
        ]);

        Livewire::test(Bookings::class)
            ->call('openPaymentLink', $leg->id)
            ->assertSet('paymentAmount', '45.000')
            ->call('createPaymentLink')
            ->assertSet('paymentLinkUrl', 'https://wanaan-bh.com/service-order/tok999');

        $this->assertSame(1, LimoPaymentLink::query()->where('leg_id', $leg->id)->count());
    }

    /** 25% / 50% of the booking total, or the full balance, in one tap. */
    public function test_the_link_offers_25_and_50_percent_of_the_total(): void
    {
        $this->enablePortal();
        $leg = $this->trip(80.0);

        $component = Livewire::test(Bookings::class)
            ->call('openPaymentLink', $leg->id)
            ->assertSee('Pay 25%')
            ->assertSee('Pay 50%')
            ->assertSee('Full balance');

        $component->call('usePaymentShare', 25)->assertSet('paymentAmount', '20.000');
        $component->call('usePaymentShare', 50)->assertSet('paymentAmount', '40.000');
        $component->call('usePaymentShare', 100)->assertSet('paymentAmount', '80.000');
        // Anything else is ignored rather than trusted from the browser.
        $component->call('usePaymentShare', 90)->assertSet('paymentAmount', '80.000');
    }

    public function test_a_share_never_asks_for_more_than_is_still_owed(): void
    {
        $this->enablePortal();
        $leg = $this->trip(80.0);
        LimoBooking::query()->whereKey($leg->legable_id)->update(['advance' => 70]);

        // 50% of 80 is 40, but only 10 is still owed.
        Livewire::test(Bookings::class)
            ->call('openPaymentLink', $leg->id)
            ->call('usePaymentShare', 50)
            ->assertSet('paymentAmount', '10.000');
    }

    /**
     * Invoke the callback controller directly — module routes only register on
     * the boot AFTER install, the known engine gap the WhatsApp webhook test
     * works around the same way.
     *
     * @param  array<string, mixed>  $payload
     */
    private function postCallback(array $payload, ?string $signature = null): \Symfony\Component\HttpFoundation\Response
    {
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) time();

        $request = Request::create('/limousine/payment-callback', 'POST', [], [], [], [
            'HTTP_' . strtoupper(str_replace('-', '_', PortalSignature::TIMESTAMP_HEADER)) => $timestamp,
            'HTTP_' . strtoupper(str_replace('-', '_', PortalSignature::SIGNATURE_HEADER)) => $signature
                ?? PortalSignature::sign($body, $timestamp, self::SECRET),
            'CONTENT_TYPE' => 'application/json',
        ], $body);

        return (new PaymentCallbackController())($request);
    }
}
