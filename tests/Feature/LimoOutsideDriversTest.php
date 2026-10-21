<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Admin\StaffRole;
use App\Erp\Admin\UserProvisioner;
use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Modules\Limousine\Livewire\OutsideDrivers;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Support\OutsideDriverPayments;
use Tests\TestCase;

/**
 * Outside drivers: each commission driver with every trip and its commission.
 */
final class LimoOutsideDriversTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function driver(string $name, string $payType = LimoDriver::PAY_TYPE_COMMISSION, ?float $usual = 15.0): LimoDriver
    {
        return LimoDriver::query()->create(['name' => $name, 'active' => true, 'pay_type' => $payType, 'commission_amount' => $usual]);
    }

    private function trip(LimoDriver $driver, string $at, ?float $pay, string $status = LimoLeg::STATUS_COMPLETED): LimoLeg
    {
        $booking = LimoBooking::query()->create([
            'customer_id' => LimoCustomer::query()->create(['name' => 'Salem Al Hajri'])->id,
            'status' => LimoBooking::STATUS_QUEUE,
        ]);

        return LimoLeg::query()->create([
            'legable_type' => LimoBooking::class, 'legable_id' => $booking->id, 'sequence' => 0,
            'status' => $status, 'start_at' => $at, 'service_type' => LimoLeg::TYPE_TRANSFER,
            'from_location' => 'Four Seasons', 'to_location' => 'Airport', 'vehicle_details' => 'Sedan',
            'rate' => 20, 'net_amount' => 20,
            'driver_id' => $driver->id, 'driver' => $driver->name, 'driver_payment' => $pay,
        ]);
    }

    private function report(): array
    {
        return (new OutsideDriverPayments(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')))->report();
    }

    public function test_each_commission_driver_lists_their_trips_and_total(): void
    {
        $rashid = $this->driver('Rashid');
        $this->trip($rashid, '2026-10-05 09:00', 12.5);
        $this->trip($rashid, '2026-10-06 09:00', 10);
        $this->trip($rashid, '2026-09-30 09:00', 99);   // last month
        $this->trip($rashid, '2026-10-07 09:00', 50, LimoLeg::STATUS_CANCELLED);

        $report = $this->report();

        $this->assertCount(1, $report['drivers']);
        $this->assertSame('Rashid', $report['drivers'][0]['name']);
        $this->assertCount(2, $report['drivers'][0]['trips']);
        $this->assertSame(22.5, $report['drivers'][0]['total']);
        $this->assertSame('Four Seasons → Airport', $report['drivers'][0]['trips'][0]['route']);
        $this->assertSame('Salem Al Hajri', $report['drivers'][0]['trips'][0]['customer']);
        $this->assertSame(22.5, $report['total']);
    }

    public function test_a_trip_with_no_amount_is_shown_and_counted_as_missing(): void
    {
        $rashid = $this->driver('Rashid');
        $this->trip($rashid, '2026-10-05 09:00', null);
        $this->trip($rashid, '2026-10-06 09:00', 10);

        $row = $this->report()['drivers'][0];

        $this->assertCount(2, $row['trips']);
        $this->assertSame(1, $row['missing']);
        $this->assertSame(10.0, $row['total']);
    }

    public function test_company_drivers_are_left_out_unless_a_trip_carries_a_commission(): void
    {
        $habib = $this->driver('Habib', LimoDriver::PAY_TYPE_COMPANY, null);
        $this->trip($habib, '2026-10-05 09:00', null);
        $this->assertSame([], $this->report()['drivers']);

        // A driver moved back to the company still has these trips to be paid.
        $this->trip($habib, '2026-10-06 09:00', 8);
        $row = $this->report()['drivers'][0];
        $this->assertFalse($row['commission']);
        $this->assertCount(1, $row['trips']);
        $this->assertSame(8.0, $row['total']);
    }

    public function test_the_page_shows_each_driver_with_their_trips(): void
    {
        $rashid = $this->driver('Rashid');
        $this->trip($rashid, '2026-10-05 09:00', 12.5);
        $this->driver('Idle Driver');

        Livewire::test(OutsideDrivers::class)
            ->assertSee('Rashid')
            ->assertSee('Four Seasons → Airport')
            ->assertSee('Idle Driver')
            ->assertSee('No trips in this period.')
            ->call('shiftMonth', -1)
            ->assertSet('month', '2026-09')
            ->assertDontSee('Four Seasons → Airport');
    }

    public function test_staff_may_not_open_it(): void
    {
        $staff = User::factory()->create(['is_admin' => false]);
        app(UserProvisioner::class)->grantApps($staff, ['limousine'], StaffRole::Staff);
        $this->actingAs($staff);

        Livewire::test(OutsideDrivers::class)->assertForbidden();
    }

    public function test_supervisors_and_accountants_may_open_it(): void
    {
        $supervisor = User::factory()->create(['is_admin' => false]);
        app(UserProvisioner::class)->grantApps($supervisor, ['limousine'], StaffRole::Supervisor);
        $this->actingAs($supervisor);
        Livewire::test(OutsideDrivers::class)->assertOk()->assertSee('Outside drivers');

        $accountant = User::factory()->create(['is_admin' => false, 'is_accountant' => true]);
        app(UserProvisioner::class)->grantApps($accountant, ['limousine'], StaffRole::Accountant);
        $this->actingAs($accountant);
        Livewire::test(OutsideDrivers::class)->assertOk();
    }

    /** The dashboard tile is admins only, so the others find it on the Limousine home. */
    public function test_the_limousine_home_links_it_for_a_supervisor_but_not_staff(): void
    {
        $supervisor = User::factory()->create(['is_admin' => false]);
        app(UserProvisioner::class)->grantApps($supervisor, ['limousine'], StaffRole::Supervisor);
        $this->actingAs($supervisor);
        Livewire::test(\Modules\Limousine\Livewire\LimoHome::class)->assertSee('/app/limousine/outside-drivers');

        $staff = User::factory()->create(['is_admin' => false]);
        app(UserProvisioner::class)->grantApps($staff, ['limousine'], StaffRole::Staff);
        $this->actingAs($staff);
        Livewire::test(\Modules\Limousine\Livewire\LimoHome::class)->assertDontSee('/app/limousine/outside-drivers');
    }

    public function test_a_supervisor_without_limousine_may_not_open_it(): void
    {
        $supervisor = User::factory()->create(['is_admin' => false]);
        app(UserProvisioner::class)->grantApps($supervisor, ['rental'], StaffRole::Supervisor);
        $this->actingAs($supervisor);

        Livewire::test(OutsideDrivers::class)->assertForbidden();
    }

    public function test_the_dashboard_tile_replaces_employees_where_limousine_runs(): void
    {
        Route::middleware('web')->group(base_path('Modules/Limousine/routes/web.php'));
        app('router')->getRoutes()->refreshNameLookups();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('/app/limousine/outside-drivers', (string) $html);
        $this->assertStringNotContainsString('/hr/employees', (string) $html);

        // A real page load renders the layout too, which Livewire::test() skips.
        $this->get('/app/limousine/outside-drivers')->assertOk()->assertSee('Outside drivers');
    }
}
