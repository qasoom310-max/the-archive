<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Limousine\Console\CarUsageReport;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * A read-only report explaining where a car's money on Fleet earnings comes
 * from, so "this car works all the time" can be checked against live data.
 */
final class LimoCarUsageReportTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');
        app(ModuleManager::class)->install('limousine');

        $kernel = $this->app?->make(Kernel::class);
        \assert($kernel instanceof ConsoleKernel);
        $kernel->registerCommand(new CarUsageReport());
    }

    public function test_it_separates_trips_with_a_car_from_trips_without_one_and_writes_nothing(): void
    {
        $car = Vehicle::query()->create(['name' => 'GMC', 'plate_no' => '125818', 'active' => true]);
        $customer = LimoCustomer::query()->create(['name' => 'Dadabhai', 'type' => 'company']);
        $booking = LimoBooking::query()->create([
            'customer_id' => $customer->id, 'pickup_at' => '2026-03-20 10:00:00',
            'fare' => 300, 'amount' => 300, 'status' => LimoBooking::STATUS_COMPLETED,
        ]);

        LimoLeg::query()->create([
            'legable_type' => LimoBooking::class, 'legable_id' => $booking->id, 'sequence' => 1,
            'car_id' => $car->id, 'start_at' => '2026-03-20 10:00:00', 'net_amount' => 100,
        ]);
        LimoLeg::query()->create([
            'legable_type' => LimoBooking::class, 'legable_id' => $booking->id, 'sequence' => 2,
            'vehicle' => 'GMC Yukon 125818', 'start_at' => '2026-03-21 10:00:00', 'net_amount' => 200,
        ]);

        $legsBefore = LimoLeg::query()->count();

        $this->artisan('limo:car-usage', ['--plate' => '125818', '--year' => 2026])
            ->expectsOutputToContain('GMC Yukon 125818')
            ->expectsOutputToContain('Limousine legs with this car in 2026: 1, amount 100.000')
            ->expectsOutputToContain('Legs with NO car but whose text mentions this plate: 1, amount 200.000')
            ->assertSuccessful();

        $this->assertSame($legsBefore, LimoLeg::query()->count());
        $this->assertNull(LimoLeg::query()->where('sequence', 2)->value('car_id'));
    }
}
