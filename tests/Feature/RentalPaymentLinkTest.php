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
use Modules\Limousine\Models\LimoPaymentLink;
use Modules\Limousine\Models\LimoPortalConfiguration;
use Modules\Limousine\Support\PortalSignature;
use Modules\Rental\Livewire\Orders;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\RentalWebBooking;
use Modules\Rental\Models\Vehicle;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * A payment link for a Rent A Car order, through the same Wanaan portal the
 * limousine bookings use: raised from the orders list, paid online, and the
 * money lands on the order's advance.
 */
final class RentalPaymentLinkTest extends TestCase
{
    use DatabaseMigrations;

    private const SECRET = 'shhh-super-secret-key';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
        app(ModuleManager::class)->install('rental');
    }

    private function enablePortal(): void
    {
        $config = LimoPortalConfiguration::current();
        $config->portal_url = 'https://wanaan-bh.com';
        $config->shared_secret = self::SECRET;
        $config->enabled = true;
        $config->save();
    }

    private function order(float $advance = 0): RentalOrder
    {
        $customer = RentalCustomer::query()->create(['name' => 'Saad AlOtaibi', 'phone' => '39000001', 'email' => 'saad@example.com']);
        $vehicle = Vehicle::query()->create(['name' => 'Ford Taurus', 'plate_no' => '111629', 'daily_rate' => 50]);

        $order = RentalOrder::query()->create([
            'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'order_date' => '2026-11-20', 'start_date' => '2026-11-20', 'end_date' => '2026-11-22',
            'rate_type' => 'daily', 'rate' => 50, 'vat_rate' => 0, 'state' => RentalOrder::STATE_ACTIVE,
            'advance_amount' => $advance,
        ]);
        $order->recalcTotals();
        $order->save();

        return $order->refresh();
    }

    private function fakePortal(): void
    {
        Http::fake([
            '*/wp-json/wanaan/v1/booking' => Http::response(['url' => 'https://wanaan-bh.com/service-order/tok1', 'token' => 'tok1'], 200),
        ]);
    }

    public function test_the_button_shows_only_when_the_portal_is_on(): void
    {
        $this->order();

        // Every row has its 3-dot Actions menu; the payment link sits inside it.
        Livewire::test(Orders::class)
            ->assertSeeHtml('data-row-actions')
            ->assertSee('Open order')
            ->assertDontSeeHtml('data-pay-link');

        $this->enablePortal();
        Livewire::test(Orders::class)->assertSeeHtml('data-pay-link');
    }

    public function test_a_link_is_made_for_the_balance_and_sent_as_a_car_hire(): void
    {
        $this->enablePortal();
        $this->fakePortal();
        $order = $this->order(advance: 20);

        Livewire::test(Orders::class)
            ->call('openPaymentLink', $order->id)
            ->assertSet('paymentAmount', '80.000')
            ->call('usePaymentShare', 50)
            ->assertSet('paymentAmount', '50.000')
            ->call('createPaymentLink')
            ->assertHasNoErrors()
            ->assertSet('paymentLinkUrl', 'https://wanaan-bh.com/service-order/tok1');

        $link = LimoPaymentLink::query()->firstOrFail();
        $this->assertSame($order->id, $link->rental_order_id);
        $this->assertNull($link->booking_id);

        Http::assertSent(function ($request) use ($order): bool {
            $data = json_decode($request->body(), true);

            return $data['service'] === 'rental'
                && $data['confirmation_no'] === $order->reference
                && $data['vehicle'] === 'Ford Taurus'
                && $data['service_date'] === '2026-11-20'
                && $data['dropoff'] === '2026-11-22'
                && $data['email'] === 'saad@example.com'
                && $data['amount'] === '50.000';
        });
    }

    public function test_more_than_the_balance_is_refused(): void
    {
        $this->enablePortal();
        $this->fakePortal();
        $order = $this->order(advance: 20);

        Livewire::test(Orders::class)
            ->call('openPaymentLink', $order->id)
            ->set('paymentAmount', '81')
            ->call('createPaymentLink')
            ->assertHasErrors(['paymentAmount']);

        $this->assertSame(0, LimoPaymentLink::query()->count());
    }

    public function test_paying_online_lands_on_the_order_once(): void
    {
        $order = $this->order(advance: 20);
        $this->enablePortal();
        $link = LimoPaymentLink::query()->create(['rental_order_id' => $order->id, 'amount' => 80]);

        $payload = ['erp_payment_id' => $link->id, 'amount' => '80.000', 'transaction_ref' => 'chg_1'];
        $this->assertSame(200, $this->postCallback($payload)->getStatusCode());
        // A replayed callback pays nothing more.
        $this->assertSame(200, $this->postCallback($payload)->getStatusCode());

        $order->refresh();
        $this->assertEqualsWithDelta(100.0, $order->advance_amount, 0.001);
        $this->assertEqualsWithDelta(0.0, $order->balance, 0.001);
        $this->assertSame(RentalOrder::PAYMENT_PAID, $order->payment_status);
        $this->assertTrue($link->refresh()->isPaid());
    }

    public function test_the_store_order_for_a_rental_link_is_not_turned_into_a_booking(): void
    {
        $row = new RentalWebBooking();
        $row->product = 'Car Rental · RO/01677';

        $this->assertTrue($row->isPaymentLink());
    }

    /** @param  array<string, mixed>  $payload */
    private function postCallback(array $payload): Response
    {
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) time();

        $request = Request::create('/limousine/payment-callback', 'POST', [], [], [], [
            'HTTP_' . strtoupper(str_replace('-', '_', PortalSignature::TIMESTAMP_HEADER)) => $timestamp,
            'HTTP_' . strtoupper(str_replace('-', '_', PortalSignature::SIGNATURE_HEADER)) => PortalSignature::sign($body, $timestamp, self::SECRET),
            'CONTENT_TYPE' => 'application/json',
        ], $body);

        return (new PaymentCallbackController())($request);
    }
}
