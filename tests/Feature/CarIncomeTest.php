<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Erp\Reports\CarIncome;
use App\Livewire\Pages\CarIncomeSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoQuotation;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * Car income: one schedule of what each car earned per month from Rent A Car
 * and Limousine together, against the car's own yearly target.
 *
 * The owner sets a target per car and does not care which desk the money came
 * through, so the two must be added on the car, month by month, and money on
 * no car must stay visible without counting toward any car's target.
 */
final class CarIncomeTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-16 10:00:00');
        app(ModuleManager::class)->install('rental');
        app(ModuleManager::class)->install('limousine');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function owner(): User
    {
        return User::factory()->create(['is_admin' => true, 'is_super_admin' => true]);
    }

    private function car(string $name, string $plate, float $yearly = 0.0, float $monthly = 0.0, bool $active = true, bool $outside = false, ?string $category = null): Vehicle
    {
        return Vehicle::query()->create([
            'name' => $name, 'plate_no' => $plate, 'yearly_target' => $yearly, 'monthly_target' => $monthly,
            'daily_rate' => 20.0, 'active' => $active, 'is_outside' => $outside, 'category' => $category,
        ]);
    }

    private function hire(?Vehicle $car, string $from, float $total, float $outside = 0.0, string $state = RentalOrder::STATE_CLOSED): void
    {
        $customer = RentalCustomer::query()->firstOrCreate(['name' => 'Renter'], ['phone' => '39000001']);

        RentalOrder::query()->create([
            'customer_id' => $customer->id, 'vehicle_id' => $car?->id, 'start_date' => $from, 'end_date' => $from,
            'total' => $total, 'outside_cost' => $outside, 'state' => $state, 'payment_status' => RentalOrder::PAYMENT_PAID,
        ]);
    }

    private function trip(?Vehicle $car, string $at, float $fare, string $bookingStatus = 'completed', string $legStatus = 'completed'): void
    {
        static $ref = 50000;
        $customer = LimoCustomer::query()->firstOrCreate(['name' => 'Agency'], ['type' => 'company']);

        $booking = LimoBooking::query()->create([
            'customer_id' => $customer->id, 'pickup_at' => $at, 'fare' => $fare, 'amount' => $fare,
            'status' => $bookingStatus, 'payment_status' => LimoBooking::PAYMENT_UNPAID,
        ]);

        LimoLeg::query()->create([
            'legable_type' => LimoBooking::class, 'legable_id' => $booking->id, 'sequence' => 0,
            'reference' => (string) $ref++, 'service_type' => 'transfer', 'status' => $legStatus,
            'start_at' => $at, 'net_amount' => $fare, 'car_id' => $car?->id,
        ]);
    }

    public function test_rent_and_limousine_are_added_on_the_car_month_by_month(): void
    {
        $car = $this->car('Lexus', '111', yearly: 18000);
        $this->hire($car, '2026-03-04', 500);
        $this->trip($car, '2026-03-20 09:00:00', 120);
        $this->trip($car, '2026-07-02 21:00:00', 80);

        $row = (new CarIncome(2026))->report()['cars'][0];

        $this->assertSame(['rent' => 500.0, 'limo' => 120.0, 'total' => 620.0], $row['months'][3]);
        $this->assertSame(['rent' => 0.0, 'limo' => 80.0, 'total' => 80.0], $row['months'][7]);
        $this->assertSame(500.0, $row['rent']);
        $this->assertSame(200.0, $row['limo']);
        $this->assertSame(700.0, $row['total']);
        $this->assertSame(18000.0, $row['target']);
        $this->assertSame(4, $row['percent']);
        $this->assertSame(17300.0, $row['remaining']);
    }

    /** Unpaid work still counts: the schedule is work done, not cash in hand. */
    public function test_it_counts_work_done_whether_or_not_it_was_paid(): void
    {
        $car = $this->car('Lexus', '111', yearly: 1000);
        $this->trip($car, '2026-02-10 09:00:00', 300); // booking is unpaid

        $this->assertSame(300.0, (new CarIncome(2026))->report()['cars'][0]['total']);
    }

    public function test_what_an_outside_owner_takes_is_not_the_cars_income(): void
    {
        $car = $this->car('Lexus', '111');
        $this->hire($car, '2026-05-01', 900, outside: 250);

        $this->assertSame(650.0, (new CarIncome(2026))->report()['cars'][0]['months'][5]['rent']);
    }

    public function test_cancelled_work_and_quotations_earn_nothing(): void
    {
        $car = $this->car('Lexus', '111');
        $this->hire($car, '2026-05-01', 900, state: RentalOrder::STATE_CANCELLED);
        $this->trip($car, '2026-05-02 09:00:00', 100, bookingStatus: 'cancelled');
        $this->trip($car, '2026-05-03 09:00:00', 100, legStatus: 'cancelled');

        // A quotation's leg naming the car: quoted, never driven.
        $quote = LimoQuotation::query()->create(['customer_id' => LimoCustomer::query()->firstOrFail()->id, 'prepared_by' => 'Desk']);
        LimoLeg::query()->create([
            'legable_type' => LimoQuotation::class, 'legable_id' => $quote->id, 'sequence' => 0, 'reference' => '59999',
            'service_type' => 'transfer', 'start_at' => '2026-05-04 09:00:00', 'net_amount' => 400, 'car_id' => $car->id,
        ]);

        $report = (new CarIncome(2026))->report();

        $this->assertSame(0.0, $report['cars'][0]['total']);
        $this->assertSame(0.0, $report['totals']['total']);
    }

    /** Money on no car is shown in its own row and counts toward no car's target. */
    public function test_money_on_no_car_is_kept_apart_and_out_of_the_targets(): void
    {
        $car = $this->car('Lexus', '111', yearly: 1000);
        $this->hire($car, '2026-01-05', 400);
        $this->trip(null, '2026-01-09 09:00:00', 600);
        $this->hire(null, '2026-02-01', 50);

        $report = (new CarIncome(2026))->report();

        $this->assertCount(1, $report['cars']);
        $this->assertSame(['rent', 'limo'], array_column($report['loose'], 'kind'));
        $this->assertSame(600.0, $report['loose'][1]['months'][1]['limo']);

        // The bottom line is everything; the target only sees the car's own 400.
        $this->assertSame(1050.0, $report['totals']['total']);
        $this->assertSame(400.0, $report['totals']['onCars']);
        $this->assertSame(40, $report['totals']['percent']);
    }

    public function test_a_car_with_no_yearly_target_uses_twelve_monthly_ones(): void
    {
        $this->car('Lexus', '111', monthly: 1500);
        $this->car('Kia', '222');

        $cars = collect((new CarIncome(2026))->report()['cars'])->keyBy('name');

        $this->assertSame(18000.0, $cars['Lexus']['target']);
        $this->assertNull($cars['Kia']['percent']); // no target, no judgement
    }

    public function test_a_retired_car_shows_only_if_it_earned_and_other_years_stay_out(): void
    {
        $sold = $this->car('Sold but earned', '111', active: false);
        $this->car('Sold and idle', '222', active: false);
        $this->hire($sold, '2026-01-05', 400);
        $this->hire($sold, '2025-06-05', 9999);

        $cars = (new CarIncome(2026))->report()['cars'];

        $this->assertSame(['Sold but earned'], array_column($cars, 'name'));
        $this->assertSame(400.0, $cars[0]['total']);
    }

    public function test_cars_are_listed_best_earner_first(): void
    {
        $this->hire($this->car('Small', '111'), '2026-01-05', 100);
        $this->hire($this->car('Big', '222'), '2026-01-05', 900);

        $this->assertSame(['Big', 'Small'], array_column((new CarIncome(2026))->report()['cars'], 'name'));
    }

    public function test_the_page_is_owner_only_and_actions_are_too(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => false]));
        $this->get('/reports/car-income')->assertForbidden();

        $this->actingAs($this->owner());
        $this->get('/reports/car-income')->assertOk()->assertSee('Car income');
    }

    public function test_the_page_shows_both_desks_and_can_show_one(): void
    {
        // No target on the car, so its 620 is printed only in the table.
        $car = $this->car('Lexus', '111');
        $this->hire($car, '2026-03-04', 500);
        $this->trip($car, '2026-03-20 09:00:00', 120);
        $this->trip(null, '2026-04-01 09:00:00', 77);
        $this->actingAs($this->owner());

        Livewire::test(CarIncomeSchedule::class)
            ->assertSee('Lexus')
            ->assertSee('620.00')                       // March, both desks
            ->assertSee('Limousine trips with no car')
            ->call('setShow', 'limo')
            ->assertSet('show', 'limo')
            ->assertSee('120.00')
            ->assertDontSee('620.00')
            ->call('setShow', 'nonsense')
            ->assertSet('show', 'all')
            ->call('setYear', 2025)
            ->assertSee('No car earned anything in 2025.')
            ->call('setYear', 1990)                     // out of range: ignored
            ->assertSet('year', 2025);
    }

    /**
     * Two schedules, never mixed: the cars we own, and the cars rented in from
     * other companies. An outside car earns only its mark-up and has no target
     * of ours, so in one table it would drag every figure of the owned fleet.
     */
    public function test_our_cars_and_outside_cars_are_two_separate_schedules(): void
    {
        $ours = $this->car('Our Lexus', '111', yearly: 1000);
        $theirs = $this->car('Their Kia', '222', outside: true, category: 'sedan');
        $this->hire($ours, '2026-01-05', 400);
        $this->hire($theirs, '2026-01-06', 900, outside: 600);   // we keep 300
        $this->trip(null, '2026-01-09 09:00:00', 50);

        $own = (new CarIncome(2026))->report();
        $this->assertSame(['Our Lexus'], array_column($own['cars'], 'name'));
        $this->assertSame(450.0, $own['totals']['total']);         // 400 + the trip on no car
        $this->assertSame(['own' => 1, 'outside' => 1], $own['counts']);
        $this->assertSame([], $own['groups']);

        $outside = (new CarIncome(2026, CarIncome::OUTSIDE))->report();
        $this->assertSame(['Their Kia'], array_column($outside['cars'], 'name'));
        $this->assertSame(300.0, $outside['totals']['total']);
        // Money on no car is listed once, with our own cars.
        $this->assertSame([], $outside['loose']);
    }

    /** Outside cars are read by category: one row each, the cars inside it. */
    public function test_outside_cars_are_grouped_by_category_in_the_owners_order(): void
    {
        $order = ['economy', 'sedan', 'semi_luxury', 'luxury', 'suv', 'semi_luxury_suv', 'luxury_suv'];
        $this->hire($this->car('Lux SUV', '1', outside: true, category: 'luxury_suv'), '2026-02-01', 500);
        $this->hire($this->car('Small A', '2', outside: true, category: 'economy'), '2026-02-01', 100);
        $this->hire($this->car('Small B', '3', outside: true, category: 'economy'), '2026-03-01', 250);
        $this->hire($this->car('Unfiled', '4', outside: true), '2026-02-01', 70);
        $this->car('Idle sedan', '5', outside: true, category: 'sedan');   // earned nothing, still listed

        $groups = (new CarIncome(2026, CarIncome::OUTSIDE, $order))->report()['groups'];

        // The owner's order, and a car with no category last, never dropped.
        $this->assertSame(['economy', 'sedan', 'luxury_suv', ''], array_column($groups, 'category'));

        $small = $groups[0];
        $this->assertSame(2, $small['count']);
        $this->assertSame(['Small B', 'Small A'], array_column($small['cars'], 'name'));
        $this->assertSame(100.0, $small['months'][2]['rent']);
        $this->assertSame(250.0, $small['months'][3]['rent']);
        $this->assertSame(350.0, $small['total']);
        $this->assertSame(0.0, $groups[1]['total']);
    }

    public function test_the_page_switches_between_the_two_schedules(): void
    {
        $this->hire($this->car('Our Lexus', '111'), '2026-01-05', 400);
        $this->hire($this->car('Their Kia', '222', outside: true, category: 'semi_luxury_suv'), '2026-01-06', 900, outside: 600);
        $this->hire($this->car('Their Unfiled', '333', outside: true), '2026-01-06', 80);
        $this->actingAs($this->owner());

        Livewire::test(CarIncomeSchedule::class)
            ->assertSee('Our cars')
            ->assertSee('Outside cars')
            ->assertSee('Our Lexus')
            ->assertDontSee('Their Kia')
            ->call('setFleet', 'outside')
            ->assertSet('fleet', 'outside')
            ->assertSee('Semi luxury SUV')
            ->assertSee('Their Kia')          // inside its category, opened by a press
            ->assertSee('No category')
            ->assertSee('Open the car and choose its category')
            ->assertDontSee('Our Lexus')
            ->call('setFleet', 'nonsense')
            ->assertSet('fleet', 'own');
    }

    public function test_the_home_page_tile_is_for_the_owner_only(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => false]));
        $this->get('/')->assertOk()->assertDontSee('/reports/car-income', false);

        $this->actingAs($this->owner());
        $this->get('/')->assertOk()->assertSee('/reports/car-income', false);
    }
}
