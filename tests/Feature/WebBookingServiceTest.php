<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Livewire\Livewire;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoReceipt;
use Modules\Limousine\Support\PortalSignature;
use Modules\Rental\Livewire\WebBookings;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\RentalPortalConfiguration;
use Modules\Rental\Models\RentalWebBooking;
use Modules\Rental\Support\WebBookingDetails;
use Tests\TestCase;

/**
 * The website sells two different things: a car hire, and a limousine trip
 * booked through the fare finder. Each becomes its own kind of record.
 */
final class WebBookingServiceTest extends TestCase
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
    private function send(array $payload): int
    {
        $body = (string) json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_' . strtoupper(str_replace('-', '_', PortalSignature::TIMESTAMP_HEADER)) => $timestamp,
            'HTTP_' . strtoupper(str_replace('-', '_', PortalSignature::SIGNATURE_HEADER)) => PortalSignature::sign($body, $timestamp, self::SECRET),
        ];
        $request = \Illuminate\Http\Request::create('/rental/web-booking', 'POST', [], [], [], $headers, $body);

        return (new \Modules\Rental\Http\Controllers\WebBookingController())($request)->getStatusCode();
    }

    /**
     * The live test order: a fare-finder trip, as plugin 1.0 sent it (no
     * places, no time) plus what 1.1 adds.
     *
     * @return array<string, mixed>
     */
    private function limoTrip(array $overrides = []): array
    {
        return array_merge([
            'source_reference' => '14949-1550',
            'ws' => null,
            'first_name' => 'Mohammed',
            'last_name' => 'Fahd',
            'phone' => '+201550600620',
            'email' => 'fahd@example.com',
            'street_address' => 'test',
            'product' => 'O#14949 P#14066 Airport Transfer — Sedan (Ford Taurus 2024)',
            'product_quantity' => 1,
            'sub_total' => 15.0,
            'total' => 15.0,
            'payment_mode' => 'Pay Later',
            'payment_status' => '',
        ], $overrides);
    }

    public function test_a_fare_finder_trip_is_sorted_as_limousine_and_a_car_hire_as_rental(): void
    {
        $this->assertSame(200, $this->send($this->limoTrip()));
        $this->assertSame(200, $this->send($this->limoTrip([
            'source_reference' => '15000-1',
            'product' => 'O#15000 P#2201 Toyota Fortuner 2024',
        ])));

        $this->assertSame(RentalWebBooking::SERVICE_LIMOUSINE, RentalWebBooking::query()->where('source_reference', '14949-1550')->value('service'));
        $this->assertSame(RentalWebBooking::SERVICE_RENTAL, RentalWebBooking::query()->where('source_reference', '15000-1')->value('service'));
    }

    public function test_the_plugins_own_word_wins(): void
    {
        $this->assertSame(RentalWebBooking::SERVICE_RENTAL, RentalWebBooking::classify(['service' => 'rental'], 'Airport Transfer — Sedan'));
        $this->assertSame(RentalWebBooking::SERVICE_LIMOUSINE, RentalWebBooking::classify(['product_slug' => 'wanaan-booking'], 'Something'));
        $this->assertSame(RentalWebBooking::SERVICE_LIMOUSINE, RentalWebBooking::classify([], 'Chauffeur — SUV (4 hours)'));
        $this->assertSame(RentalWebBooking::SERVICE_RENTAL, RentalWebBooking::classify([], 'Mercedes Vito'));
    }

    public function test_places_and_time_are_read_from_the_lines_details(): void
    {
        $this->send($this->limoTrip([
            'service' => 'limousine',
            'item_meta' => [
                ['label' => 'Service', 'value' => 'Airport Transfer'],
                ['label' => 'Pick-up location', 'value' => 'Bahrain International Airport'],
                ['label' => 'Drop-off location', 'value' => 'Four Seasons, Bahrain Bay'],
                ['label' => 'Pick-up date', 'value' => '24/10/2026'],
                ['label' => 'Pick-up time', 'value' => '18:30'],
                ['label' => 'Flight number', 'value' => 'GF 007'],
                ['label' => 'Passengers', 'value' => '2'],
            ],
        ]));

        $web = RentalWebBooking::query()->firstOrFail();
        $this->assertSame('Bahrain International Airport', $web->pickup_location);
        $this->assertSame('Four Seasons, Bahrain Bay', $web->dropoff_location);
        $this->assertSame('2026-10-24 18:30', $web->pickup_at?->format('Y-m-d H:i'));
        $this->assertNull($web->dropoff_at);
        $this->assertCount(7, $web->details());
    }

    public function test_details_under_hidden_keys_are_read_too(): void
    {
        $lines = WebBookingDetails::lines(['item_meta' => [], 'item_meta_raw' => [
            'wnf_pickup' => 'Seef Mall',
            'wnf_dropoff' => 'Airport',
            'wnf_pickup_datetime' => '2026-10-24T09:15',
        ]]);

        $this->assertSame('Seef Mall', WebBookingDetails::pickupLocation($lines));
        $this->assertSame('Airport', WebBookingDetails::dropoffLocation($lines));
        $this->assertSame('2026-10-24 09:15', WebBookingDetails::pickupAt($lines)?->format('Y-m-d H:i'));
    }

    public function test_a_number_is_never_read_as_a_date(): void
    {
        $this->assertNull(WebBookingDetails::parse('3'));
        $this->assertNull(WebBookingDetails::parse('Sedan'));
    }

    public function test_a_limousine_trip_becomes_a_limousine_booking_in_the_queue(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true, 'name' => 'Abbas']));
        $this->send($this->limoTrip([
            'item_meta' => [
                ['label' => 'Pick-up location', 'value' => 'Bahrain International Airport'],
                ['label' => 'Drop-off location', 'value' => 'Juffair'],
                ['label' => 'Pick-up date & time', 'value' => '24/10/2026 18:30'],
                ['label' => 'Flight number', 'value' => 'GF 007'],
            ],
        ]));
        $web = RentalWebBooking::query()->firstOrFail();

        Livewire::test(WebBookings::class)->call('view', $web->id)
            ->assertSee('Create limousine booking')
            ->assertDontSee('Create rental order')
            ->call('createLimoBooking')
            ->assertRedirect();

        $booking = LimoBooking::query()->firstOrFail();
        $leg = $booking->legs()->firstOrFail();

        $this->assertSame('Mohammed Fahd', $booking->pax_name);
        $this->assertSame('Abbas', $booking->prepared_by);
        $this->assertSame(LimoLeg::STATUS_QUEUE, $leg->status);
        $this->assertSame(LimoLeg::TYPE_TRANSFER, $leg->service_type);
        $this->assertSame('Bahrain International Airport', $leg->from_location);
        $this->assertSame('Juffair', $leg->to_location);
        $this->assertSame('2026-10-24 18:30', $leg->start_at?->format('Y-m-d H:i'));
        $this->assertSame('Airport Transfer — Sedan (Ford Taurus 2024)', $leg->vehicle_details);
        $this->assertNull($leg->car_id);
        $this->assertSame(15.0, (float) $booking->fare);
        $this->assertStringContainsString('Flight number: GF 007', (string) $booking->notes);

        // Not paid on the website: nothing received, nothing written off.
        $this->assertSame(0.0, (float) $booking->advance);
        $this->assertSame(0, LimoReceipt::query()->count());

        $web->refresh();
        $this->assertSame(RentalWebBooking::STATUS_COMPLETE, $web->status);
        $this->assertSame((int) $booking->id, $web->limo_booking_id);
        $this->assertSame(0, RentalOrder::query()->count());
    }

    public function test_a_trip_paid_on_the_website_gets_its_receipt(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->send($this->limoTrip(['payment_mode' => 'Tap', 'payment_status' => 'paid', 'total' => 45.0]));
        $web = RentalWebBooking::query()->firstOrFail();

        Livewire::test(WebBookings::class)->call('view', $web->id)->call('createLimoBooking');

        $booking = LimoBooking::query()->firstOrFail();
        $this->assertSame(45.0, (float) $booking->advance);
        $this->assertSame(LimoBooking::PAYMENT_PAID, $booking->paymentState());
        $this->assertSame(1, LimoReceipt::query()->where('booking_id', $booking->id)->count());
    }

    public function test_the_trips_customer_is_found_by_phone(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $known = RentalCustomer::query()->create(['name' => 'Mohammed F.', 'phone' => '01550600620']);
        $this->send($this->limoTrip());

        Livewire::test(WebBookings::class)->call('view', RentalWebBooking::query()->value('id'))->call('createLimoBooking');

        $this->assertSame((int) $known->id, (int) LimoBooking::query()->value('customer_id'));
    }

    public function test_a_limousine_trip_cannot_become_a_rental_order_or_the_other_way(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->send($this->limoTrip());
        $this->send($this->limoTrip(['source_reference' => '15000-1', 'product' => 'Toyota Fortuner 2024']));
        $limo = RentalWebBooking::query()->where('source_reference', '14949-1550')->firstOrFail();
        $rental = RentalWebBooking::query()->where('source_reference', '15000-1')->firstOrFail();

        Livewire::test(WebBookings::class)->call('view', $limo->id)->call('createOrder');
        Livewire::test(WebBookings::class)->call('view', $rental->id)->call('createLimoBooking');

        $this->assertSame(0, RentalOrder::query()->count());
        $this->assertSame(0, LimoBooking::query()->count());
    }

    public function test_a_person_can_switch_the_service_and_the_website_does_not_switch_it_back(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->send($this->limoTrip());
        $web = RentalWebBooking::query()->firstOrFail();

        Livewire::test(WebBookings::class)->call('view', $web->id)
            ->call('switchService')
            ->assertSee('Create rental order');

        $this->send($this->limoTrip(['payment_status' => 'paid']));

        $web->refresh();
        $this->assertSame(RentalWebBooking::SERVICE_RENTAL, $web->service);
        $this->assertTrue($web->service_manual);
    }

    public function test_the_list_filters_by_service(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->send($this->limoTrip(['first_name' => 'Limo', 'last_name' => 'Rider']));
        $this->send($this->limoTrip(['source_reference' => '15000-1', 'product' => 'Toyota Fortuner 2024', 'first_name' => 'Car', 'last_name' => 'Hirer']));

        Livewire::test(WebBookings::class)->set('service', 'limousine')
            ->assertSee('Limo Rider')->assertDontSee('Car Hirer');
        Livewire::test(WebBookings::class)->set('service', 'rental')
            ->assertSee('Car Hirer')->assertDontSee('Limo Rider');
        Livewire::test(WebBookings::class)
            ->assertSee('Car Hirer')->assertSee('Limo Rider');
    }

    public function test_no_limousine_button_without_permission_to_book_one(): void
    {
        $this->grantEveryone('rental.web_booking');
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->send($this->limoTrip());
        $id = RentalWebBooking::query()->value('id');

        Livewire::test(WebBookings::class)->call('view', $id)
            ->assertDontSee('Create limousine booking')
            ->call('createLimoBooking')
            ->assertForbidden();

        $this->assertSame(0, LimoBooking::query()->count());
    }
}
