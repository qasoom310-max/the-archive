<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\Bookings;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Tests\TestCase;

/** On the bookings list a company's trips show who rides under the company name. */
final class LimoBookingPassengerTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private function trip(string $customer, string $type, string $pax): void
    {
        $booking = LimoBooking::query()->create([
            'customer_id' => LimoCustomer::query()->create(['name' => $customer, 'type' => $type, 'phone' => '+97337151555'])->id,
            'status' => LimoBooking::STATUS_QUEUE, 'pax_name' => $pax, 'pax_contact' => '+966537212691',
        ]);
        $booking->legs()->create([
            'sequence' => 0, 'service_type' => LimoLeg::TYPE_TRANSFER, 'status' => LimoLeg::STATUS_QUEUE,
            'from_location' => 'Airport', 'to_location' => 'Riyadh', 'start_at' => '2026-10-23 11:30',
            'rate' => 150, 'rate_basis' => 'trip', 'net_amount' => 150,
        ]);
    }

    public function test_a_company_booking_shows_its_passenger_under_the_name(): void
    {
        $this->trip('Frame media', 'company', 'Khalid Al Otaibi');
        $this->trip('Helen Friberg', 'individual', 'Lars Friberg');
        $this->trip('Gulf Air', 'company', 'gulf air');

        $html = Livewire::test(Bookings::class)->call('openTab', 'all')->html();

        // Only the company with a real passenger gets the line: a private customer is
        // the passenger, and a name that only repeats the company adds nothing.
        preg_match_all('/data-passenger>([^<]*)</', $html, $m);
        $this->assertSame(['PAX: Khalid Al Otaibi'], array_map('trim', $m[1]));
    }
}
