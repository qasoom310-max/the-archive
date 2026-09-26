<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Support\PortalSignature;
use Modules\Rental\Livewire\WebBookings;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\RentalPortalConfiguration;
use Modules\Rental\Models\RentalWebBooking;
use Tests\TestCase;

/**
 * Bookings taken on the WordPress website, arriving in the ERP.
 *
 * The endpoint faces the open internet with no session behind it, so most of
 * what matters here is what it REFUSES. The rest is the thing that went wrong
 * on the old system: one order reaching the pending list nine times, because
 * the plugin sent on every reload of the thank-you page and nothing carried a
 * reference to tell a re-send from a new booking.
 */
final class RentalWebBookingTest extends TestCase
{
    use DatabaseMigrations;

    private const SECRET = 'a-shared-secret-for-the-website';

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('rental');
    }

    private function openForBusiness(): void
    {
        RentalPortalConfiguration::query()->create([
            'shared_secret' => self::SECRET,
            'enabled' => true,
        ]);
    }

    /**
     * Invoke the controller directly — a module's routes only register on the
     * boot AFTER it is installed, the known engine gap the limousine payment
     * callback and the WhatsApp webhook tests both work around the same way.
     *
     * @param  array<string, mixed>  $payload
     */
    private function send(array $payload, ?string $secret = self::SECRET, bool $sign = true): \Symfony\Component\HttpFoundation\Response
    {
        $body = (string) json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();

        $headers = ['CONTENT_TYPE' => 'application/json'];

        if ($sign) {
            $headers['HTTP_'.strtoupper(str_replace('-', '_', PortalSignature::TIMESTAMP_HEADER))] = $timestamp;
            $headers['HTTP_'.strtoupper(str_replace('-', '_', PortalSignature::SIGNATURE_HEADER))] = PortalSignature::sign($body, $timestamp, (string) $secret);
        }

        $request = \Illuminate\Http\Request::create('/rental/web-booking', 'POST', [], [], [], $headers, $body);

        return (new \Modules\Rental\Http\Controllers\WebBookingController())($request);
    }

    /** @param array<string, mixed> $payload */
    private function assertSent(array $payload, int $status, ?string $secret = self::SECRET, bool $sign = true): void
    {
        $this->assertSame($status, $this->send($payload, $secret, $sign)->getStatusCode());
    }

    /** @return array<string, mixed> */
    private function booking(array $overrides = []): array
    {
        return array_merge([
            'source_reference' => '14113-42',
            'first_name' => 'Qassim',
            'last_name' => 'Almutairi',
            'phone' => '+97333112233',
            'email' => 'qassim@example.com',
            'street_address' => 'Road 4101',
            'town' => 'Juffair',
            'product' => 'O#14113 P#14066 Wanaan Booking',
            'product_quantity' => 1,
            'pickup_location' => 'Bahrain Airport',
            'dropoff_location' => 'Juffair',
            'pickup_datetime' => '2026-10-03 09:00:00',
            'dropoff_datetime' => '2026-10-06 09:00:00',
            'sub_total' => 45.0,
            'total' => 45.0,
            'payment_mode' => 'Tap',
            'payment_status' => 'paid',
        ], $overrides);
    }

    public function test_a_signed_booking_is_recorded(): void
    {
        $this->openForBusiness();

        $this->assertSent($this->booking(), 200);

        $booking = RentalWebBooking::query()->firstOrFail();

        $this->assertSame('14113-42', $booking->source_reference);
        $this->assertSame('Qassim Almutairi', $booking->customerName());
        $this->assertSame('Bahrain Airport', $booking->pickup_location);
        $this->assertSame(45.0, $booking->total);
        $this->assertTrue($booking->isPaidOnline());
        $this->assertTrue($booking->isPending());
    }

    /**
     * The bug that put one order on the old system's list nine times: the
     * plugin sent again on every reload of the order-received page.
     */
    public function test_the_same_booking_sent_again_does_not_make_a_second_row(): void
    {
        $this->openForBusiness();

        $this->assertSent($this->booking(), 200);
        $this->assertSent($this->booking(), 200);
        $this->assertSent($this->booking(), 200);

        $this->assertSame(1, RentalWebBooking::query()->count());
    }

    /** A second car in the same checkout IS a second job for the desk. */
    public function test_a_second_line_item_is_its_own_booking(): void
    {
        $this->openForBusiness();

        $this->assertSent($this->booking(), 200);
        $this->assertSent($this->booking(['source_reference' => '14113-43']), 200);

        $this->assertSame(2, RentalWebBooking::query()->count());
    }

    public function test_an_unsigned_request_is_refused(): void
    {
        $this->openForBusiness();

        $this->assertSent($this->booking(), 401, sign: false);

        $this->assertSame(0, RentalWebBooking::query()->count());
    }

    public function test_a_wrongly_signed_request_is_refused(): void
    {
        $this->openForBusiness();

        $this->assertSent($this->booking(), 401, 'not-the-secret');

        $this->assertSame(0, RentalWebBooking::query()->count());
    }

    /** Switched off, nothing is accepted — not even a correctly signed request. */
    public function test_nothing_is_accepted_until_it_is_switched_on(): void
    {
        RentalPortalConfiguration::query()->create([
            'shared_secret' => self::SECRET,
            'enabled' => false,
        ]);

        $this->assertSent($this->booking(), 403);

        $this->assertSame(0, RentalWebBooking::query()->count());
    }

    public function test_a_booking_with_no_reference_is_refused(): void
    {
        $this->openForBusiness();

        $payload = $this->booking();
        unset($payload['source_reference']);

        $this->assertSent($payload, 422);
    }

    /**
     * The website sends fields this screen has no column for, and a booking is
     * a customer's own words about dates and money — so the request is kept
     * whole, not just the parts we happen to read today.
     */
    public function test_the_whole_request_is_kept_verbatim(): void
    {
        $this->openForBusiness();

        $this->assertSent($this->booking(['something_new' => 'a field we do not map yet']), 200);

        $booking = RentalWebBooking::query()->firstOrFail();

        $this->assertSame('a field we do not map yet', $booking->payload['something_new'] ?? null);
    }

    /** The site has no date fields switched on: the booking still lands. */
    public function test_a_booking_with_no_dates_is_still_accepted(): void
    {
        $this->openForBusiness();

        $this->assertSent($this->booking([
            'pickup_datetime' => null,
            'dropoff_datetime' => null,
            'pickup_location' => '',
        ]), 200);

        $booking = RentalWebBooking::query()->firstOrFail();

        $this->assertNull($booking->pickup_at);
        $this->assertNull($booking->pickup_location);
    }

    // ---- the screen -------------------------------------------------

    private function admin(): User
    {
        $user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_the_screen_lists_pending_bookings(): void
    {
        $this->openForBusiness();
        $this->assertSent($this->booking(), 200);
        $this->admin();

        Livewire::test(WebBookings::class)
            ->assertOk()
            ->assertSee('Qassim')
            ->assertSee('14113-42');
    }

    public function test_creating_an_order_fills_in_what_the_website_knew(): void
    {
        $this->openForBusiness();
        $this->assertSent($this->booking(), 200);
        $this->admin();

        $booking = RentalWebBooking::query()->firstOrFail();

        Livewire::test(WebBookings::class)
            ->call('view', $booking->id)
            ->call('createOrder');

        $order = RentalOrder::query()->firstOrFail();
        $customer = RentalCustomer::query()->firstOrFail();

        $this->assertSame('Qassim Almutairi', $customer->name);
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertSame('2026-10-03', $order->start_date?->toDateString());
        $this->assertSame('2026-10-06', $order->end_date?->toDateString());

        // Money that genuinely changed hands on the website, so the desk does
        // not ask for it a second time.
        $this->assertSame(45.0, (float) $order->advance_amount);

        // The car is NEVER guessed: the website sells one generic product, not
        // a vehicle from the fleet.
        $this->assertNull($order->vehicle_id);

        $booking->refresh();
        $this->assertSame(RentalWebBooking::STATUS_COMPLETE, $booking->status);
        $this->assertSame((int) $order->id, $booking->rental_order_id);
    }

    /**
     * A number the website sends with its country code is the same person we
     * hold locally without one — the rule the customer import already uses.
     */
    public function test_an_existing_customer_is_matched_by_their_number(): void
    {
        $this->openForBusiness();
        $existing = RentalCustomer::query()->create(['name' => 'Qassim A', 'phone' => '33112233']);

        $this->assertSent($this->booking(), 200);
        $this->admin();

        Livewire::test(WebBookings::class)
            ->call('view', RentalWebBooking::query()->firstOrFail()->id)
            ->call('createOrder');

        $this->assertSame(1, RentalCustomer::query()->count(), 'The website booking made a second copy of a customer we already had.');
        $this->assertSame($existing->id, RentalOrder::query()->firstOrFail()->customer_id);
    }

    /**
     * A reloaded thank-you page after the desk has dealt with the booking must
     * not put a finished job back on the queue.
     */
    public function test_a_repeat_delivery_does_not_reopen_a_finished_booking(): void
    {
        $this->openForBusiness();
        $this->assertSent($this->booking(), 200);
        $this->admin();

        $booking = RentalWebBooking::query()->firstOrFail();
        Livewire::test(WebBookings::class)->call('view', $booking->id)->call('createOrder');

        $this->assertSent($this->booking(), 200);

        $booking->refresh();
        $this->assertSame(RentalWebBooking::STATUS_COMPLETE, $booking->status);
        $this->assertSame(1, RentalOrder::query()->count());
    }

    public function test_the_screen_needs_permission(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        Livewire::test(WebBookings::class)->assertForbidden();
    }
}
