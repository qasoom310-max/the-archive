<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Erp\Settings\Setting;
use App\Erp\Targets\RevenueTargets;
use App\Erp\Views\ValueFormat;
use App\Livewire\Pages\SettingsPage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\LimoHome;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Rental\Livewire\RentalHome;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Tests\TestCase;

/**
 * The revenue card on either dashboard states the business's whole income, so
 * it belongs to the owner alone - and so do the monthly and yearly targets,
 * because a box reading "62% of target" gives the same figure away to anyone
 * who can divide.
 */
final class RevenueTargetsTest extends TestCase
{
    use DatabaseMigrations;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        // Mid-year, mid-month: far enough from either edge that a boundary bug
        // shows up as a missing sale rather than an ambiguous one.
        $this->today = CarbonImmutable::create(2026, 9, 15, 11, 0, 0);
        CarbonImmutable::setTestNow($this->today);

        app(ModuleManager::class)->install('rental');
        app(ModuleManager::class)->install('limousine');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function owner(): User
    {
        return User::factory()->create(['is_admin' => true, 'is_super_admin' => true]);
    }

    /** A regular admin: bypasses every ACL, and must still not see the takings. */
    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true, 'is_super_admin' => false]);
    }

    private function rentalOrder(string $date, float $total, string $payment = RentalOrder::PAYMENT_PAID, float $outsideCost = 0.0): void
    {
        $customer = RentalCustomer::query()->firstOrCreate(['name' => 'Qassim'], ['phone' => '39000001']);

        RentalOrder::query()->create([
            'customer_id' => $customer->id,
            'start_date' => $date,
            'end_date' => $date,
            'total' => $total,
            'outside_cost' => $outsideCost,
            'state' => RentalOrder::STATE_CLOSED,
            'payment_status' => $payment,
        ]);
    }

    private function rentalOrderOwing(string $date, float $total, float $balance): void
    {
        $customer = RentalCustomer::query()->firstOrCreate(['name' => 'Qassim'], ['phone' => '39000001']);

        RentalOrder::query()->create([
            'customer_id' => $customer->id,
            'start_date' => $date,
            'end_date' => $date,
            'total' => $total,
            'outside_cost' => 0.0,
            'balance' => $balance,
            'state' => RentalOrder::STATE_ACTIVE,
            'payment_status' => RentalOrder::PAYMENT_UNPAID,
        ]);
    }

    private function limoBooking(string $date, float $fare, string $payment = LimoBooking::PAYMENT_PAID, float $advance = 0.0): void
    {
        $customer = LimoCustomer::query()->firstOrCreate(['name' => 'Dadabhai Travel'], ['type' => 'company']);

        LimoBooking::query()->create([
            'customer_id' => $customer->id,
            'pickup_at' => $date.' 10:00:00',
            'fare' => $fare,
            'amount' => $fare,
            'advance' => $advance,
            'status' => LimoBooking::STATUS_COMPLETED,
            'payment_status' => $payment,
        ]);
    }

    // ── Who may see the money ────────────────────────────────────────────────

    public function test_a_regular_admin_sees_no_revenue_or_targets_on_either_dashboard(): void
    {
        $this->rentalOrder('2026-09-10', 4321.5);
        $this->limoBooking('2026-09-10', 8765.25);
        $this->actingAs($this->admin());

        Livewire::test(RentalHome::class)
            ->assertDontSee(ValueFormat::money(4321.5))
            ->assertDontSee('Monthly target')
            ->assertDontSee('Yearly target');

        Livewire::test(LimoHome::class)
            ->assertDontSee(ValueFormat::money(8765.25))
            ->assertDontSee('Monthly target')
            ->assertDontSee('Yearly target');
    }

    public function test_the_owner_sees_revenue_and_both_target_boxes(): void
    {
        $this->rentalOrder('2026-09-10', 4321.5);
        $this->limoBooking('2026-09-10', 8765.25);
        $this->actingAs($this->owner());

        Livewire::test(RentalHome::class)
            ->assertSee(ValueFormat::money(4321.5))
            ->assertSee('Monthly target')
            ->assertSee('Yearly target');

        Livewire::test(LimoHome::class)
            ->assertSee(ValueFormat::money(8765.25))
            ->assertSee('Monthly target')
            ->assertSee('Yearly target');
    }

    public function test_chasing_an_unpaid_balance_stays_with_the_operational_cards(): void
    {
        // Gating the revenue card must not take the "who still owes us" link
        // with it - that is counter work, not a report on the takings.
        $this->rentalOrder('2026-09-10', 600, RentalOrder::PAYMENT_UNPAID);
        $this->actingAs($this->admin());

        Livewire::test(RentalHome::class)
            ->assertSee('Unpaid Orders')
            ->assertSee('/app/rental/order?tab=unpaid', false);
    }

    public function test_only_the_owner_can_change_the_targets(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(RentalHome::class)->call('openTargets')->assertForbidden();

        // Re-checked on the action itself: Livewire dispatches straight to a
        // method, so a gate that only runs when the modal opens is no gate.
        Livewire::test(RentalHome::class)
            ->set('targetMonthly', '9999')
            ->call('saveTargets')
            ->assertForbidden();

        $this->assertNull(app(RevenueTargets::class)->monthly('rental'));
    }

    public function test_the_owner_saves_targets_and_the_dashboard_reads_them_back(): void
    {
        $this->actingAs($this->owner());

        Livewire::test(RentalHome::class)
            ->call('openTargets')
            ->set('targetMonthly', '5000')
            ->set('targetYearly', '72000')
            ->call('saveTargets')
            ->assertHasNoErrors()
            ->assertSet('editingTargets', false);

        $targets = app(RevenueTargets::class);
        $this->assertEqualsWithDelta(5000.0, $targets->monthly('rental'), 0.001);
        $this->assertEqualsWithDelta(72000.0, $targets->yearly('rental'), 0.001);
    }

    public function test_each_app_keeps_its_own_targets(): void
    {
        $targets = app(RevenueTargets::class);
        $targets->set('rental', 5000.0, 72000.0);
        $targets->set('limousine', 9000.0, 130000.0);

        $this->assertEqualsWithDelta(5000.0, $targets->monthly('rental'), 0.001);
        $this->assertEqualsWithDelta(9000.0, $targets->monthly('limousine'), 0.001);
        $this->assertEqualsWithDelta(72000.0, $targets->yearly('rental'), 0.001);
        $this->assertEqualsWithDelta(130000.0, $targets->yearly('limousine'), 0.001);
    }

    public function test_an_emptied_box_clears_the_target_rather_than_setting_it_to_zero(): void
    {
        app(RevenueTargets::class)->set('rental', 5000.0, 72000.0);
        $this->actingAs($this->owner());

        Livewire::test(RentalHome::class)
            ->call('openTargets')
            ->assertSet('targetMonthly', '5000')
            ->set('targetMonthly', '')
            ->call('saveTargets')
            ->assertHasNoErrors();

        // Null, not 0.0 - "no target" and "a target of nothing" are different
        // answers, and only one of them can be 0% attained.
        $this->assertNull(app(RevenueTargets::class)->monthly('rental'));
        $this->assertEqualsWithDelta(72000.0, app(RevenueTargets::class)->yearly('rental'), 0.001);
    }

    public function test_a_target_that_is_not_a_number_is_refused(): void
    {
        $this->actingAs($this->owner());

        Livewire::test(RentalHome::class)
            ->call('openTargets')
            ->set('targetMonthly', 'lots')
            ->call('saveTargets')
            ->assertHasErrors(['targetMonthly']);

        $this->assertNull(app(RevenueTargets::class)->monthly('rental'));
    }

    // ── What the figures actually count ──────────────────────────────────────

    public function test_a_sale_on_the_first_day_of_the_month_counts_toward_it(): void
    {
        // rental_orders.start_date is declared DATE but Eloquent writes it into
        // SQLite as "2026-09-01 00:00:00", and SQLite compares that as a plain
        // string - so a date-string upper bound loses the last day of the month
        // and a datetime lower bound loses the first.
        $this->rentalOrder('2026-09-01', 100);
        $this->rentalOrder('2026-09-30', 250);
        $this->rentalOrder('2026-08-31', 999);

        $progress = app(RevenueTargets::class)->progress('rental', $this->today);

        $this->assertEqualsWithDelta(350.0, $progress['month']['earned'], 0.001);
    }

    public function test_a_booking_at_the_last_minute_of_the_month_still_counts(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Late Night', 'type' => 'company']);
        LimoBooking::query()->create([
            'customer_id' => $customer->id,
            'pickup_at' => '2026-09-30 23:45:00',
            'fare' => 80,
            'amount' => 80,
            'status' => LimoBooking::STATUS_COMPLETED,
            'payment_status' => LimoBooking::PAYMENT_PAID,
        ]);

        $progress = app(RevenueTargets::class)->progress('limousine', $this->today);

        $this->assertEqualsWithDelta(80.0, $progress['month']['earned'], 0.001);
    }

    public function test_rental_progress_is_net_of_what_outside_vendors_are_paid(): void
    {
        // The dashboard's own revenue card is the markup, not the gross. A
        // target measured against a different number from the card beside it
        // would be worse than no target at all.
        $this->rentalOrder('2026-09-10', 500, RentalOrder::PAYMENT_PAID, 300);

        $progress = app(RevenueTargets::class)->progress('rental', $this->today);

        $this->assertEqualsWithDelta(200.0, $progress['month']['earned'], 0.001);
    }

    public function test_money_not_yet_collected_does_not_count(): void
    {
        $this->rentalOrder('2026-09-10', 400, RentalOrder::PAYMENT_PAID);
        $this->rentalOrder('2026-09-11', 900, RentalOrder::PAYMENT_UNPAID);
        $this->limoBooking('2026-09-10', 150, LimoBooking::PAYMENT_PAID);
        $this->limoBooking('2026-09-11', 700, LimoBooking::PAYMENT_UNPAID);

        $targets = app(RevenueTargets::class);

        $this->assertEqualsWithDelta(400.0, $targets->progress('rental', $this->today)['month']['earned'], 0.001);
        $this->assertEqualsWithDelta(150.0, $targets->progress('limousine', $this->today)['month']['earned'], 0.001);
    }

    public function test_the_year_box_counts_the_whole_year_and_stops_there(): void
    {
        $this->rentalOrder('2026-01-01', 100);
        $this->rentalOrder('2026-09-10', 200);
        $this->rentalOrder('2026-12-31', 300);
        $this->rentalOrder('2025-12-31', 999);

        $progress = app(RevenueTargets::class)->progress('rental', $this->today);

        $this->assertEqualsWithDelta(600.0, $progress['year']['earned'], 0.001);
        $this->assertEqualsWithDelta(200.0, $progress['month']['earned'], 0.001);
    }

    public function test_attainment_and_what_is_left_to_earn(): void
    {
        app(RevenueTargets::class)->set('rental', 1000.0, 12000.0);
        $this->rentalOrder('2026-09-10', 250);

        $progress = app(RevenueTargets::class)->progress('rental', $this->today);

        $this->assertSame(25, $progress['month']['pct']);
        $this->assertEqualsWithDelta(750.0, $progress['month']['remaining'], 0.001);
    }

    public function test_beating_a_target_leaves_nothing_to_go(): void
    {
        app(RevenueTargets::class)->set('rental', 100.0, 1200.0);
        $this->rentalOrder('2026-09-10', 140);

        $progress = app(RevenueTargets::class)->progress('rental', $this->today);

        $this->assertSame(140, $progress['month']['pct']);
        $this->assertEqualsWithDelta(0.0, $progress['month']['remaining'], 0.001);
    }

    public function test_no_target_means_no_percentage_rather_than_zero_percent(): void
    {
        $this->rentalOrder('2026-09-10', 250);

        $progress = app(RevenueTargets::class)->progress('rental', $this->today);

        $this->assertNull($progress['month']['target']);
        $this->assertNull($progress['month']['pct']);
        $this->assertNull($progress['pace']);
    }

    public function test_the_year_is_paced_against_the_part_of_it_that_has_gone(): void
    {
        // 15 September is day 258 of 365, so a year on schedule has banked
        // 258/365 of its target. Comparing the part-year against the WHOLE
        // target would read 71% and look like failure every month but December.
        app(RevenueTargets::class)->set('rental', null, 36500.0);
        $this->rentalOrder('2026-09-10', 25800.0);

        $progress = app(RevenueTargets::class)->progress('rental', $this->today);

        $this->assertSame(71, $progress['year']['pct']);
        $this->assertSame(100, $progress['pace']);
    }

    public function test_targets_are_never_offered_as_rows_on_the_central_settings_page(): void
    {
        // SettingManager drops an unknown key into the General group, so without
        // an exclusion a regular admin would find both the figure and the box to
        // change it sitting on a page they are allowed to open.
        app(RevenueTargets::class)->set('rental', 5000.0, 72000.0);
        $this->actingAs($this->owner());

        Livewire::test(SettingsPage::class)
            ->assertDontSee('targets.rental.monthly')
            ->assertDontSee('targets.rental.yearly');

        // The values are still stored - hidden from that page, not thrown away.
        $this->assertSame('5000', Setting::get('targets.rental.monthly'));
    }

    // ── What is still owed ───────────────────────────────────────────────────

    public function test_a_rental_order_owes_its_balance_in_the_month_it_falls_in(): void
    {
        $this->rentalOrderOwing('2026-09-10', 500, 200);
        $this->rentalOrderOwing('2026-08-10', 900, 400);

        $targets = app(RevenueTargets::class);
        $progress = $targets->progress('rental', $this->today);

        $this->assertEqualsWithDelta(200.0, $progress['month']['unpaid'], 0.001);
        $this->assertEqualsWithDelta(600.0, $progress['year']['unpaid'], 0.001);
        // No window at all is every unpaid job on the books.
        $this->assertEqualsWithDelta(600.0, $progress['outstanding'], 0.001);
    }

    public function test_a_booking_owes_its_fare_less_the_advance_taken(): void
    {
        // A booking has no balance column - a part-payment is still "unpaid",
        // and what it owes is whatever the advance did not cover.
        $this->limoBooking('2026-09-10', 300, LimoBooking::PAYMENT_UNPAID, 120);
        $this->limoBooking('2026-09-11', 100, LimoBooking::PAYMENT_PAID, 100);

        $progress = app(RevenueTargets::class)->progress('limousine', $this->today);

        $this->assertEqualsWithDelta(180.0, $progress['month']['unpaid'], 0.001);
        $this->assertEqualsWithDelta(100.0, $progress['month']['earned'], 0.001);
    }

    public function test_an_overpaid_booking_cannot_cancel_out_what_another_owes(): void
    {
        // Floored per row: without that, an advance larger than the fare would
        // subtract a debt that does not exist from one that does.
        $this->limoBooking('2026-09-10', 300, LimoBooking::PAYMENT_UNPAID, 120);
        $this->limoBooking('2026-09-11', 50, LimoBooking::PAYMENT_UNPAID, 400);

        $this->assertEqualsWithDelta(180.0, app(RevenueTargets::class)->outstanding('limousine'), 0.001);
    }

    public function test_a_cancelled_booking_owes_nothing(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Walked Away', 'type' => 'company']);
        LimoBooking::query()->create([
            'customer_id' => $customer->id,
            'pickup_at' => '2026-09-10 10:00:00',
            'fare' => 400,
            'amount' => 400,
            'advance' => 0,
            'status' => LimoBooking::STATUS_CANCELLED,
            'payment_status' => LimoBooking::PAYMENT_UNPAID,
        ]);

        $this->assertEqualsWithDelta(0.0, app(RevenueTargets::class)->outstanding('limousine'), 0.001);
    }

    public function test_unpaid_work_does_not_count_toward_attainment(): void
    {
        // A month whose customers have not paid must not read "target met".
        app(RevenueTargets::class)->set('rental', 1000.0, 12000.0);
        $this->rentalOrder('2026-09-10', 400);
        $this->rentalOrderOwing('2026-09-11', 900, 900);

        $progress = app(RevenueTargets::class)->progress('rental', $this->today);

        $this->assertSame(40, $progress['month']['pct']);
        $this->assertEqualsWithDelta(900.0, $progress['month']['unpaid'], 0.001);
    }

    public function test_the_owner_sees_what_is_still_owed_beside_the_takings(): void
    {
        $this->rentalOrder('2026-09-10', 1000);
        $this->rentalOrderOwing('2026-09-11', 700, 640.75);
        $this->actingAs($this->owner());

        Livewire::test(RentalHome::class)
            ->assertSee(ValueFormat::money(1000.0))
            ->assertSee(ValueFormat::money(640.75));
    }
}
