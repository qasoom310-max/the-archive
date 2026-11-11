<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoReceipt;
use Modules\Limousine\Support\PortalSignature;
use Modules\Rental\Http\Controllers\WebBookingController;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\RentalPortalConfiguration;
use Modules\Rental\Models\RentalWebBooking;
use Tests\TestCase;

/**
 * The fare finder lets a customer pay part of the trip online (20% inside
 * Bahrain, 50% leaving it) and the rest to the driver. The website sends the
 * trip price as `total` and what the card was charged as `amount_paid`: the
 * trip is booked at its full price, with a receipt for the part paid and the
 * rest owing.
 */
final class WebBookingPartPaymentTest extends TestCase
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
            'source_reference' => '16001-1',
            'first_name' => 'Helen',
            'last_name' => 'Friberg',
            'phone' => '+97339000000',
            'email' => 'helen@example.com',
            'product' => 'O#16001 P#14066 Airport Transfer — Sedan (Ford Taurus 2024)',
            'product_slug' => 'wanaan-booking',
            'pickup_location' => 'Bahrain Airport',
            'dropoff_location' => 'Juffair',
            'pickup_datetime' => now()->addDay()->format('Y-m-d') . ' 10:00:00',
            'sub_total' => 50.0,
            'total' => 50.0,
            'amount_paid' => 10.0,
            'payment_mode' => 'Tap',
            'payment_status' => 'paid',
        ], $overrides);
    }

    public function test_a_trip_paid_part_now_is_booked_at_its_full_price_with_the_rest_owing(): void
    {
        $this->send($this->trip());

        $web = RentalWebBooking::query()->firstOrFail();
        $booking = LimoBooking::query()->firstOrFail();

        $this->assertTrue($web->isPartPaid());
        $this->assertSame(10.0, $web->paidAmount());
        $this->assertSame(50.0, (float) $booking->fare);
        $this->assertSame(10.0, round((float) LimoReceipt::query()->sum('amount'), 3));
        $this->assertSame(40.0, round($booking->refresh()->amountOwed(), 3));
        $this->assertStringContainsString('to collect in the car', (string) $booking->notes);
    }

    public function test_an_older_delivery_without_the_paid_figure_paid_its_whole_total(): void
    {
        $payload = $this->trip();
        unset($payload['amount_paid']);
        $this->send($payload);

        $web = RentalWebBooking::query()->firstOrFail();
        $this->assertFalse($web->isPartPaid());
        $this->assertSame(50.0, $web->paidAmount());
        $this->assertSame(50.0, round((float) LimoReceipt::query()->sum('amount'), 3));
    }

    public function test_nothing_is_received_before_the_card_is_charged(): void
    {
        // A pay-later order: nothing has been paid yet.
        $this->send($this->trip(['payment_mode' => 'Pay Later', 'payment_status' => '']));

        $this->assertSame(0.0, RentalWebBooking::query()->firstOrFail()->paidAmount());
        $this->assertSame(0, LimoReceipt::query()->count());
    }

    public function test_a_paid_figure_above_the_trip_price_is_never_taken(): void
    {
        $this->send($this->trip(['amount_paid' => 80.0]));

        $this->assertSame(50.0, RentalWebBooking::query()->firstOrFail()->paidAmount());
        $this->assertSame(50.0, round((float) LimoReceipt::query()->sum('amount'), 3));
    }

    public function test_a_car_hire_paid_part_now_carries_only_that_as_its_advance(): void
    {
        $this->send($this->trip([
            'source_reference' => '16002-1',
            'product' => 'O#16002 P#11429 Taurus 2024',
            'product_slug' => 'taurus-2024',
            'dropoff_datetime' => now()->addDays(4)->format('Y-m-d') . ' 10:00:00',
            'total' => 60.0,
            'amount_paid' => 12.0,
        ]));

        $this->assertSame(12.0, (float) RentalOrder::query()->firstOrFail()->advance_amount);
    }
}
