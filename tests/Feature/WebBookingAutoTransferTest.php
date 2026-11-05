<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoReceipt;
use Modules\Limousine\Support\PortalSignature;
use Modules\Rental\Http\Controllers\WebBookingController;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\RentalPortalConfiguration;
use Modules\Rental\Models\RentalWebBooking;
use Tests\TestCase;

/**
 * A website booking goes straight to where the desk works: a limousine trip
 * into Limousine → Bookings, a car hire into Rent A Car → Orders. A booking
 * that sat on the Web bookings page went unnoticed.
 */
final class WebBookingAutoTransferTest extends TestCase
{
    use DatabaseMigrations;

    private const SECRET = 'a-shared-secret-for-the-website';

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('rental');
        app(ModuleManager::class)->install('limousine');
        RentalPortalConfiguration::query()->create(['shared_secret' => self::SECRET, 'enabled' => true]);
    }

    /** @param array<string, mixed> $payload */
    private function send(array $payload): void
    {
        $body = (string) json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $request = Request::create('/rental/web-booking', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_' . strtoupper(str_replace('-', '_', PortalSignature::TIMESTAMP_HEADER)) => $timestamp,
            'HTTP_' . strtoupper(str_replace('-', '_', PortalSignature::SIGNATURE_HEADER)) => PortalSignature::sign($body, $timestamp, self::SECRET),
        ], $body);

        $this->assertSame(200, (new WebBookingController())($request)->getStatusCode());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function trip(array $overrides = []): array
    {
        return array_merge([
            'source_reference' => '15192-1825',
            'first_name' => 'Standrew',
            'last_name' => 'Bitanny',
            'phone' => '+16198465532',
            'email' => 'standrew@example.com',
            'product' => 'O#15192 P#14066 Bahrain → Saudi Arabia — Sedan (Ford Taurus 2024)',
            'product_slug' => 'wanaan-booking',
            'pickup_location' => 'Hilton Hotel, Juffair',
            'dropoff_location' => 'Dammam, Sheraton Hotel',
            'pickup_datetime' => now()->addDay()->format('Y-m-d') . ' 10:00:00',
            'sub_total' => 140.0,
            'total' => 140.0,
            'payment_mode' => 'Pay Later',
            'payment_status' => '',
        ], $overrides);
    }

    public function test_a_pay_later_trip_lands_in_the_limousine_queue(): void
    {
        $this->send($this->trip());

        $web = RentalWebBooking::query()->firstOrFail();
        $booking = LimoBooking::query()->firstOrFail();
        $leg = $booking->legs()->firstOrFail();

        $this->assertFalse($web->isPending());
        $this->assertSame($booking->id, $web->limo_booking_id);
        $this->assertSame('Website', $booking->prepared_by);
        $this->assertSame(LimoLeg::STATUS_QUEUE, $leg->status);
        $this->assertSame('Hilton Hotel, Juffair', $leg->from_location);
        $this->assertSame(140.0, (float) $booking->fare);
        $this->assertSame(0, LimoReceipt::query()->count());
    }

    public function test_a_paid_car_hire_lands_in_rental_orders_with_its_payment(): void
    {
        $this->send($this->trip([
            'source_reference' => '15200-1',
            'product' => 'O#15200 P#11429 Taurus 2024',
            'product_slug' => 'taurus-2024',
            'dropoff_datetime' => now()->addDays(4)->format('Y-m-d') . ' 10:00:00',
            'total' => 60.0,
            'payment_mode' => 'Tap',
            'payment_status' => 'paid',
        ]));

        $web = RentalWebBooking::query()->firstOrFail();
        $order = RentalOrder::query()->firstOrFail();

        $this->assertSame($order->id, $web->rental_order_id);
        $this->assertSame(60.0, (float) $order->advance_amount);
        $this->assertSame(0, LimoBooking::query()->count());
    }

    public function test_an_unpaid_card_attempt_waits_and_moves_once_paid(): void
    {
        $this->send($this->trip(['payment_mode' => 'Tap']));

        $this->assertTrue(RentalWebBooking::query()->firstOrFail()->isPending());
        $this->assertSame(0, LimoBooking::query()->count());

        // The website sends the order again when the payment goes through.
        $this->send($this->trip(['payment_mode' => 'Tap', 'payment_status' => 'paid']));
        $this->send($this->trip(['payment_mode' => 'Tap', 'payment_status' => 'paid']));

        $this->assertSame(1, LimoBooking::query()->count());
        $this->assertSame(1, LimoReceipt::query()->count());
        $this->assertSame(140.0, (float) LimoReceipt::query()->value('amount'));
    }

    public function test_a_payment_link_order_is_not_booked_twice(): void
    {
        $this->send($this->trip([
            'product' => 'O#15193 P#14045 Limousine Service · Trip 26128',
            'product_slug' => 'limousine-service',
            'payment_mode' => 'Tap',
            'payment_status' => 'paid',
        ]));

        $web = RentalWebBooking::query()->firstOrFail();
        $this->assertFalse($web->isPending());
        $this->assertNull($web->limo_booking_id);
        $this->assertSame(0, LimoBooking::query()->count());
        $this->assertSame(0, RentalOrder::query()->count());
    }

    public function test_an_older_payment_link_order_without_a_trip_number_is_recognised(): void
    {
        $this->send($this->trip(['product' => 'O#15001 P#14045 Limousine Service', 'product_slug' => '']));

        $this->assertSame(0, LimoBooking::query()->count());
        $this->assertFalse(RentalWebBooking::query()->firstOrFail()->isPending());
    }

    public function test_the_waiting_bookings_are_moved_once_on_deploy(): void
    {
        $make = static fn (string $ref, string $product, ?string $pickup, string $mode = 'Pay Later'): RentalWebBooking => RentalWebBooking::query()->create([
            'source_reference' => $ref,
            'first_name' => 'Najla',
            'phone' => '+966509930022',
            'product' => $product,
            'pickup_location' => 'Bahrain Airport',
            'dropoff_location' => 'Riyadh',
            'pickup_at' => $pickup,
            'total' => 75.0,
            'payment_mode' => $mode,
            'payment_status' => '',
            'status' => RentalWebBooking::STATUS_PENDING,
            'service' => str_contains($product, 'P#14066') || str_contains($product, 'Limousine') ? RentalWebBooking::SERVICE_LIMOUSINE : RentalWebBooking::SERVICE_RENTAL,
        ]);

        $future = $make('1-1', 'O#1 P#14066 Bahrain → Saudi Arabia — Luxury SUV', now()->addDays(2)->toDateTimeString());
        $past = $make('2-1', 'O#2 P#14066 Airport Transfer — Sedan', now()->subDays(3)->toDateTimeString());
        $link = $make('3-1', 'O#3 P#14045 Limousine Service · Trip 26005', now()->subDays(6)->toDateTimeString(), 'Tap');
        $card = $make('4-1', 'O#4 P#14066 Airport Transfer — SUV', now()->addDay()->toDateTimeString(), 'Tap');

        (require base_path('Modules/Rental/database/migrations/2026_11_05_900062_transfer_pending_web_bookings.php'))->up();

        $this->assertNotNull($future->fresh()?->limo_booking_id);
        $this->assertTrue((bool) $past->fresh()?->isPending());
        $this->assertFalse((bool) $link->fresh()?->isPending());
        $this->assertNull($link->fresh()?->limo_booking_id);
        $this->assertTrue((bool) $card->fresh()?->isPending());
        $this->assertSame(1, LimoBooking::query()->count());
    }
}
