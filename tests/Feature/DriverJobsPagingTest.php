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
use Modules\Rental\Livewire\DriverJobs;
use Modules\Rental\Models\Driver;
use Tests\TestCase;

/**
 * Long lists: twenty-five at a time, searchable, and as many as three hundred
 * when somebody is going through them properly.
 *
 * A driver on four jobs a day fills a month with a hundred and twenty. The
 * whole lot on one screen is a scroll with no bottom, and a fixed page size is
 * its own annoyance the day you need the year.
 */
final class DriverJobsPagingTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true, 'name' => 'Qassim']));
        app(ModuleManager::class)->install('limousine');
        app(ModuleManager::class)->install('rental');
    }

    /** A driver with $count limousine trips behind them. */
    private function driverWithJobs(int $count): Driver
    {
        $driver = Driver::query()->create(['name' => 'Rashid']);
        $customer = LimoCustomer::query()->create(['name' => 'Helen']);

        $booking = LimoBooking::query()->create([
            'reference' => 'BK/00001',
            'customer_id' => $customer->id,
            'pickup_at' => now(),
            'status' => LimoBooking::STATUS_COMPLETED,
            'prepared_by' => 'Qassim',
        ]);

        for ($i = 1; $i <= $count; $i++) {
            LimoLeg::query()->create([
                'legable_type' => LimoBooking::class,
                'legable_id' => $booking->id,
                'sequence' => $i,
                'reference' => (string) (70000 + $i),
                'status' => LimoLeg::STATUS_COMPLETED,
                'start_at' => now()->subDays($i),
                'from_location' => 'Hotel',
                'to_location' => 'Airport',
                'driver_id' => $driver->id,
                'vehicle' => $i % 2 === 0 ? 'FORD EXPEDITION' : 'Eco Sport',
                'rate' => 45, 'net_amount' => 45,
            ]);
        }

        return $driver;
    }

    public function test_it_shows_twenty_five_at_a_time_by_default(): void
    {
        $driver = $this->driverWithJobs(60);

        $component = Livewire::test(DriverJobs::class, ['driverId' => $driver->id])
            ->assertSet('perPage', 25)
            ->assertViewHas('total', 60);

        $this->assertCount(25, $component->viewData('jobs')->items());
    }

    public function test_the_rest_are_on_further_pages(): void
    {
        $driver = $this->driverWithJobs(60);

        $component = Livewire::test(DriverJobs::class, ['driverId' => $driver->id]);

        // Newest first: page one opens on yesterday's job.
        $component->assertSee('70001')->assertDontSee('70060');

        $component->call('setPage', 3, 'jobsPage');
        // 60 jobs at twenty-five a page leaves ten on the third.
        $this->assertCount(10, $component->viewData('jobs')->items());
        $component->assertSee('70060');
    }

    public function test_the_office_can_ask_for_three_hundred(): void
    {
        $driver = $this->driverWithJobs(120);

        $component = Livewire::test(DriverJobs::class, ['driverId' => $driver->id]);
        $this->assertCount(25, $component->viewData('jobs')->items());

        $component->call('setPerPage', 300)->assertSet('perPage', 300);
        // The month over, all at once.
        $this->assertCount(120, $component->viewData('jobs')->items());
    }

    /** A page size nobody offered is refused rather than trusted. */
    public function test_an_unoffered_page_size_is_ignored(): void
    {
        $driver = $this->driverWithJobs(60);

        Livewire::test(DriverJobs::class, ['driverId' => $driver->id])
            ->call('setPerPage', 100000)
            ->assertSet('perPage', 25);
    }

    public function test_the_history_can_be_searched(): void
    {
        $driver = $this->driverWithJobs(25);

        $component = Livewire::test(DriverJobs::class, ['driverId' => $driver->id])
            ->set('search', '70007');

        $this->assertCount(1, $component->viewData('jobs')->items());
        $this->assertSame(1, $component->viewData('total'));
    }

    /** Searching the car is the same question asked another way. */
    public function test_it_searches_the_car_too(): void
    {
        $driver = $this->driverWithJobs(10);

        $component = Livewire::test(DriverJobs::class, ['driverId' => $driver->id])
            ->set('search', 'Eco Sport');

        // The odd-numbered legs, five of ten.
        $this->assertSame(5, $component->viewData('total'));
    }

    public function test_searching_returns_to_the_first_page(): void
    {
        $driver = $this->driverWithJobs(60);

        Livewire::test(DriverJobs::class, ['driverId' => $driver->id])
            ->call('setPage', 3, 'jobsPage')
            ->set('search', '70001')
            // Page 3 of a one-row result would otherwise show nothing.
            ->assertSee('70001');
    }

    /* ── The same on the trip queue ──────────────────────────────────────── */

    private function queueOf(int $count): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Helen']);

        for ($i = 1; $i <= $count; $i++) {
            $booking = LimoBooking::query()->create([
                'reference' => 'BK/' . $i,
                'customer_id' => $customer->id,
                'pickup_at' => now()->subDays($i),
                'status' => LimoBooking::STATUS_QUEUE,
            ]);

            LimoLeg::query()->create([
                'legable_type' => LimoBooking::class,
                'legable_id' => $booking->id,
                'sequence' => 0,
                'reference' => (string) (80000 + $i),
                'status' => LimoLeg::STATUS_QUEUE,
                'start_at' => now()->subDays($i),
                'from_location' => 'Hotel',
                'to_location' => 'Airport',
                'rate' => 45, 'net_amount' => 45,
            ]);
        }
    }

    public function test_the_queue_shows_twenty_five_and_offers_three_hundred(): void
    {
        $this->queueOf(60);

        $component = Livewire::test(Bookings::class)->assertSet('perPage', 25);
        $this->assertCount(25, $component->viewData('legs')->items());

        $component->call('setPerPage', 300)->assertSet('perPage', 300);
        $this->assertCount(60, $component->viewData('legs')->items());
    }

    /** The page size holds across the tabs — it is how the office reads, not a per-tab setting. */
    public function test_the_page_size_survives_a_tab_change(): void
    {
        $this->queueOf(60);

        Livewire::test(Bookings::class)
            ->call('setPerPage', 50)
            ->set('tab', 'queue')
            ->assertSet('perPage', 50);
    }

    public function test_the_queue_refuses_a_page_size_nobody_offered(): void
    {
        $this->queueOf(5);

        Livewire::test(Bookings::class)
            ->call('setPerPage', 99999)
            ->assertSet('perPage', 25);
    }
}
