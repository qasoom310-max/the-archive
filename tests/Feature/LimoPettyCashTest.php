<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\PettyAdvancePage;
use Modules\Limousine\Livewire\PettyCash;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoExpense;
use Modules\Limousine\Models\LimoPettyAdvance;
use Modules\Limousine\Services\PettyCash as PettyCashService;
use Tests\TestCase;

/**
 * Petty cash: the float, and each advance's life — issued by the manager,
 * confirmed by the accountant, backed by paper, then settled.
 *
 * The settlement is the arithmetic the office used to do by hand: receipts
 * under the amount → recorded as short (never taken from salary); over it →
 * reimbursed from the float. Both snapshotted, so a later edit to a line
 * cannot move what the settlement decided.
 */
final class LimoPettyCashTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('limousine');
    }

    private function asManager(): User
    {
        $user = User::factory()->create(['is_admin' => true, 'name' => 'Mohmd']);
        $this->actingAs($user);

        return $user;
    }

    private function asAccountant(): User
    {
        $user = User::factory()->create(['is_admin' => true, 'is_accountant' => true, 'name' => 'Amal']);
        $this->actingAs($user);

        return $user;
    }

    private function driver(string $name = 'Hassan'): LimoDriver
    {
        return LimoDriver::query()->create(['name' => $name, 'phone' => '39000001']);
    }

    /** Float topped up and an advance issued, ready for the lifecycle. */
    private function issued(float $topUp = 200, float $amount = 100): LimoPettyAdvance
    {
        $petty = app(PettyCashService::class);
        $petty->topUp($topUp, '2026-09-01', null, 'Qassim');

        $advance = $petty->issue($this->driver(), $amount, '2026-09-02', null, 'Mohmd');
        $this->assertNotNull($advance);

        return $advance;
    }

    public function test_the_float_balance_is_topups_less_what_went_out(): void
    {
        $this->asManager();
        $this->issued(topUp: 200, amount: 100);

        $this->assertEqualsWithDelta(100.0, app(PettyCashService::class)->floatBalance(), 0.001);
        $this->assertEqualsWithDelta(100.0, app(PettyCashService::class)->outstanding(), 0.001);
    }

    /** The Open tab gathers each driver's advances under one row with their total. */
    public function test_the_open_tab_groups_advances_by_driver(): void
    {
        $this->asManager();
        $petty = app(PettyCashService::class);
        $petty->topUp(500, '2026-09-01', null, 'Qassim');
        $sohail = $this->driver('Sohail');
        $ali = $this->driver('Ali Makhlooq');
        $petty->issue($sohail, 50, '2026-09-02', null, 'Mohmd');
        $petty->issue($ali, 30, '2026-09-03', null, 'Mohmd');
        $petty->issue($sohail, 20, '2026-09-05', null, 'Mohmd');

        $component = \Livewire\Livewire::test(\Modules\Limousine\Livewire\PettyCash::class);
        $groups = $component->viewData('groups');

        $this->assertCount(2, $groups);
        $sohailGroup = collect($groups)->firstWhere('name', 'Sohail');
        $this->assertCount(2, $sohailGroup['advances']);
        $this->assertEqualsWithDelta(70.0, $sohailGroup['given'], 0.001);
        // Newest first inside the driver.
        $this->assertSame('2026-09-05', $sohailGroup['advances'][0]->date?->toDateString());
        $component->assertSee('2 advances');
    }

    public function test_cash_the_float_does_not_hold_cannot_be_handed_out(): void
    {
        $this->asManager();
        app(PettyCashService::class)->topUp(50, '2026-09-01', null, 'Qassim');

        Livewire::test(PettyCash::class)
            ->call('openIssue')
            ->set('issueDriverId', $this->driver()->id)
            ->set('issueAmount', '100')
            ->call('saveIssue')
            ->assertHasErrors('issueAmount');

        $this->assertSame(0, LimoPettyAdvance::query()->count());
    }

    public function test_issuing_is_the_managers_not_every_staff_members(): void
    {
        $this->grantEveryone('limousine.petty_cash');
        $this->actingAs(User::factory()->create());

        Livewire::test(PettyCash::class)
            ->call('openIssue')
            ->assertForbidden();
    }

    /** The supervisor accountant (not an admin) runs the whole desk. */
    public function test_the_supervisor_accountant_has_every_petty_cash_option(): void
    {
        $this->grantEveryone('limousine.petty_cash');
        $this->actingAs(User::factory()->create(['is_accountant' => true, 'name' => 'Amal']));
        $driver = $this->driver();

        Livewire::test(PettyCash::class)
            ->assertSee(__('Top up float'))
            ->assertSee(__('Send to driver'))
            ->assertSee(__('Import'))
            ->call('openTopUp')
            ->set('topAmount', '200')
            ->call('saveTopUp')
            ->assertHasNoErrors()
            ->call('openIssue')
            ->set('issueDriverId', $driver->id)
            ->set('issueAmount', '100')
            ->call('saveIssue')
            ->assertHasNoErrors();

        $advance = LimoPettyAdvance::query()->firstOrFail();
        $this->assertEqualsWithDelta(100.0, app(PettyCashService::class)->floatBalance(), 0.001);

        Livewire::test(PettyAdvancePage::class, ['id' => $advance->id])
            ->call('openCategory')
            ->set('newCategory', 'Tyre puncture')
            ->call('saveCategory')
            ->assertHasNoErrors()
            ->call('confirm')
            ->call('openSettle')
            ->call('saveSettle');

        $this->assertSame(LimoPettyAdvance::STATUS_CLEARED, $advance->fresh()?->status);
    }

    /** A supervisor given the desk in full gets every option; another supervisor does not. */
    public function test_a_supervisor_granted_the_petty_cash_desk_has_every_option(): void
    {
        $this->grantEveryone('limousine.petty_cash');
        $driver = $this->driver();

        $this->actingAs(User::factory()->create(['name' => 'Other supervisor']));
        Livewire::test(PettyCash::class)->assertDontSee(__('Send to driver'))->call('openIssue')->assertForbidden();

        $abbas = User::factory()->create(['name' => 'Abbas', 'petty_cash_full' => true]);
        $this->assertFalse($abbas->isAccountant(), 'Not made an accountant.');
        $this->assertFalse($abbas->canConfirmPayments(), 'No payment-confirmation power elsewhere.');
        $this->actingAs($abbas);

        Livewire::test(PettyCash::class)
            ->assertSee(__('Top up float'))
            ->assertSee(__('Send to driver'))
            ->call('openTopUp')->set('topAmount', '200')->call('saveTopUp')->assertHasNoErrors()
            ->call('openIssue')->set('issueDriverId', $driver->id)->set('issueAmount', '100')->call('saveIssue')->assertHasNoErrors();

        $advance = LimoPettyAdvance::query()->firstOrFail();
        Livewire::test(PettyAdvancePage::class, ['id' => $advance->id])
            ->call('openCategory')->set('newCategory', 'Car wash')->call('saveCategory')->assertHasNoErrors()
            ->call('confirm')
            ->call('openSettle')
            ->call('saveSettle');

        $this->assertSame(LimoPettyAdvance::STATUS_CLEARED, $advance->fresh()?->status);
    }

    public function test_the_migration_grants_abbas_the_desk_by_email(): void
    {
        $abbas = User::factory()->create(['email' => 'HamdanAbbas98@gmail.com']);
        $other = User::factory()->create();

        $migration = require base_path('database/migrations/2026_10_05_100001_add_petty_cash_full_to_users.php');
        $migration->up(); // column already there: only re-grants, idempotent

        $this->assertTrue($abbas->fresh()?->hasFullPettyCash());
        $this->assertFalse($other->fresh()?->hasFullPettyCash());
    }

    public function test_confirming_takes_the_accountant_not_a_regular_admin(): void
    {
        $this->asManager();
        $advance = $this->issued();

        // The manager who handed the cash out cannot also vouch it happened.
        Livewire::test(PettyAdvancePage::class, ['id' => $advance->id])
            ->call('confirm')
            ->assertForbidden();

        $this->asAccountant();
        Livewire::test(PettyAdvancePage::class, ['id' => $advance->id])
            ->call('confirm');

        $fresh = $advance->fresh();
        $this->assertSame(LimoPettyAdvance::STATUS_CONFIRMED, $fresh?->status);
        $this->assertSame('Amal', $fresh?->confirmed_by);
    }

    /** A shortfall is only recorded: it is never taken from the driver's salary (2026-10-27). */
    public function test_receipts_short_of_the_amount_are_recorded_but_never_deducted_from_salary(): void
    {
        $this->asAccountant();
        $advance = $this->issued(amount: 100);
        app(PettyCashService::class)->confirm($advance, 'Amal');

        // The driver hands back 84 in paper: 50 fuel, 34 parking.
        $page = Livewire::test(PettyAdvancePage::class, ['id' => $advance->id])
            ->set('lineDate', '2026-09-02')->set('lineCategory', 'Fuel')->set('lineAmount', '50')
            ->call('addLine')
            ->set('lineDate', '2026-09-02')->set('lineCategory', 'Parking')->set('lineAmount', '34')
            ->call('addLine')
            ->call('openSettle');
        $page->assertSee('It will not be deducted from')
            ->assertDontSee('will be deducted from')
            ->call('saveSettle')
            ->assertHasNoErrors();

        $fresh = $advance->fresh();
        $this->assertSame(LimoPettyAdvance::STATUS_CLEARED, $fresh?->status);
        $this->assertEqualsWithDelta(84.0, $fresh?->receipts_total, 0.001);
        // He was given 100 and can show 84 — the 16 is recorded as short, nothing more.
        $this->assertEqualsWithDelta(16.0, $fresh?->shortfall, 0.001);
        $this->assertEqualsWithDelta(0.0, $fresh?->excess, 0.001);

        Livewire::test(PettyAdvancePage::class, ['id' => $advance->id])
            ->assertSee('Not deducted from');
    }

    public function test_receipts_over_the_amount_are_paid_back_from_the_float(): void
    {
        $this->asAccountant();
        $advance = $this->issued(topUp: 200, amount: 100);
        app(PettyCashService::class)->confirm($advance, 'Amal');

        $advance->lines()->create(['date' => '2026-09-02', 'category' => 'Fuel', 'amount' => 106]);

        Livewire::test(PettyAdvancePage::class, ['id' => $advance->id])
            ->call('openSettle')
            ->call('saveSettle');

        $fresh = $advance->fresh();
        $this->assertEqualsWithDelta(6.0, $fresh?->excess, 0.001);
        // He spent 6 of his own — the float pays him back, so its balance
        // carries the full 106 the road actually cost.
        $this->assertEqualsWithDelta(94.0, app(PettyCashService::class)->floatBalance(), 0.001);
    }

    public function test_settling_writes_every_receipt_into_the_expense_ledger(): void
    {
        $this->asAccountant();
        $advance = $this->issued(amount: 100);
        app(PettyCashService::class)->confirm($advance, 'Amal');

        $advance->lines()->create(['date' => '2026-09-02', 'category' => 'Fuel', 'amount' => 50, 'description' => 'petrol']);
        $advance->lines()->create(['date' => '2026-09-02', 'category' => 'Spare parts', 'amount' => 10]);

        app(PettyCashService::class)->settle($advance->fresh() ?? $advance, 'Amal');

        // One truth: the expense reports read the same money the settlement
        // did, filed under the driver's name.
        $this->assertSame(2, LimoExpense::query()->count());
        // Filed in the ledger's matching slots, not dumped in "other".
        $this->assertSame(1, LimoExpense::query()->where('category', 'spare_parts')->count());
        $fuel = LimoExpense::query()->where('category', 'fuel')->firstOrFail();
        $this->assertSame('Hassan', $fuel->payee);
        $this->assertStringContainsString((string) $advance->reference, (string) $fuel->notes);
    }

    public function test_a_cleared_advance_is_locked(): void
    {
        $this->asAccountant();
        $advance = $this->issued(amount: 100);
        $petty = app(PettyCashService::class);
        $petty->confirm($advance, 'Amal');
        $petty->settle($advance->fresh() ?? $advance, 'Amal');

        // What was decided about a man's salary must not move afterwards.
        Livewire::test(PettyAdvancePage::class, ['id' => $advance->id])
            ->set('lineDate', '2026-09-02')->set('lineCategory', 'Fuel')->set('lineAmount', '50')
            ->call('addLine');

        $this->assertSame(0, $advance->fresh()?->lines()->count());
    }

    public function test_an_unconfirmed_advance_cannot_be_settled(): void
    {
        $this->asAccountant();
        $advance = $this->issued();

        // Settling paper against a hand-over nobody vouched for would skip the
        // step that makes the paper mean anything.
        $this->assertNull(app(PettyCashService::class)->settle($advance, 'Amal'));
        $this->assertSame(LimoPettyAdvance::STATUS_ISSUED, $advance->fresh()?->status);
    }

    public function test_a_line_can_name_the_car_and_keeps_it_as_a_snapshot(): void
    {
        $this->asAccountant();
        $car = \Modules\Rental\Models\Vehicle::query()->create(['name' => 'GMC Yukon', 'plate_no' => '12345']);

        $advance = $this->issued(amount: 100);
        app(PettyCashService::class)->confirm($advance, 'Amal');

        Livewire::test(PettyAdvancePage::class, ['id' => $advance->id])
            ->set('lineDate', '2026-09-02')->set('lineCategory', 'Fuel')
            ->set('lineAmount', '50')->set('lineCarId', (string) $car->id)
            ->call('addLine')
            ->assertHasNoErrors();

        $line = $advance->fresh()?->lines()->firstOrFail();
        $this->assertSame($car->id, $line->car_id);
        $this->assertStringContainsString('GMC Yukon', (string) $line->vehicle);

        // The slip keeps saying which car even after the car is renamed: a
        // settled advance is history, not a live join.
        $car->forceFill(['name' => 'Sold Unit'])->save();
        $this->assertStringContainsString('GMC Yukon', (string) $line->fresh()?->vehicle);

        // And the ledger hears about it at settlement.
        app(PettyCashService::class)->settle($advance->fresh() ?? $advance, 'Amal');
        $this->assertStringContainsString('GMC Yukon', (string) LimoExpense::query()->firstOrFail()->notes);
    }

    public function test_the_owner_grows_the_category_list_from_the_page(): void
    {
        $this->asManager();
        $advance = $this->issued();

        Livewire::test(PettyAdvancePage::class, ['id' => $advance->id])
            ->call('openCategory')
            ->set('newCategory', 'Car decoration')
            ->call('saveCategory')
            ->assertHasNoErrors()
            // Selected and immediately usable on the next line.
            ->assertSet('lineCategory', 'Car decoration')
            ->set('lineDate', '2026-09-02')->set('lineAmount', '12')
            ->call('addLine')
            ->assertHasNoErrors();

        $this->assertSame('Car decoration', $advance->fresh()?->lines()->firstOrFail()->category);
    }

    public function test_an_invented_category_settles_into_other_keeping_its_name(): void
    {
        $this->asAccountant();
        \Modules\Limousine\Models\LimoPettyCategory::query()->create(['name' => 'Car decoration']);

        $advance = $this->issued(amount: 100);
        $petty = app(PettyCashService::class);
        $petty->confirm($advance, 'Amal');
        $advance->lines()->create(['date' => '2026-09-02', 'category' => 'Car decoration', 'amount' => 30]);
        $petty->settle($advance->fresh() ?? $advance, 'Amal');

        // The ledger's taxonomy is fixed, so the name rides in the notes
        // rather than being lost.
        $expense = LimoExpense::query()->firstOrFail();
        $this->assertSame('other', $expense->category);
        $this->assertStringContainsString('Car decoration', (string) $expense->notes);
    }

    public function test_a_made_up_category_is_refused_on_a_line(): void
    {
        $this->asManager();
        $advance = $this->issued();

        // The select is a convenience; the rule is the owner's table.
        Livewire::test(PettyAdvancePage::class, ['id' => $advance->id])
            ->set('lineDate', '2026-09-02')->set('lineCategory', 'Nonsense')->set('lineAmount', '10')
            ->call('addLine')
            ->assertHasErrors('lineCategory');
    }

    /** The month report shows the shortfall as Short and has no salary-deduction list any more. */
    public function test_the_month_report_shows_the_shortfall_without_a_salary_deduction_list(): void
    {
        $this->asAccountant();
        $advance = $this->issued(amount: 100);
        $petty = app(PettyCashService::class);
        $petty->confirm($advance, 'Amal');
        $advance->lines()->create(['date' => now()->format('Y-m-d'), 'category' => 'Fuel', 'amount' => 84]);
        $petty->settle($advance->fresh() ?? $advance, 'Amal');

        Livewire::test(PettyCash::class)
            ->set('tab', 'report')
            ->set('month', now()->format('Y-m'))
            ->assertSee('Hassan')
            ->assertSee('Short')
            ->assertSee('16.00')
            ->assertDontSee('Salary deductions this month')
            ->assertDontSee('From salary');
    }

    /** Top-ups in, advances out, overspend paid back: one running balance that lands on the card. */
    public function test_the_float_statement_lists_every_movement_with_a_running_balance(): void
    {
        $this->asAccountant();
        $petty = app(PettyCashService::class);
        $petty->topUp(500, '2026-09-01', 'Opening cash', 'Qassim');
        $advance = $petty->issue($this->driver('Hassan'), 100, '2026-09-02', null, 'Mohmd');
        $this->assertNotNull($advance);
        $petty->confirm($advance, 'Amal');
        $advance->lines()->create(['date' => '2026-09-03', 'category' => 'Fuel', 'amount' => 110]);
        $petty->settle($advance->fresh() ?? $advance, 'Amal');
        $petty->issue($this->driver('Sohail'), 50, '2026-09-04', null, 'Mohmd');

        $all = $petty->statement();
        $this->assertSame(['topup', 'advance', 'advance', 'paid_back'], array_column($all['rows'], 'kind'));
        $this->assertSame([500.0, 400.0, 350.0, 340.0], array_column($all['rows'], 'balance'));
        $this->assertEqualsWithDelta($petty->floatBalance(), $all['closing'], 0.001);
        $this->assertSame(1, $all['totals']['paid_back_count']);
        $this->assertEqualsWithDelta(150.0, $all['totals']['given'], 0.001);
    }

    /** A month opens on everything before it, so opening + in − out = closing. */
    public function test_a_months_statement_carries_the_balance_brought_forward(): void
    {
        $this->asManager();
        $petty = app(PettyCashService::class);
        $petty->topUp(300, '2026-08-20', null, 'Qassim');
        $petty->issue($this->driver(), 120, '2026-08-25', null, 'Mohmd');
        $petty->topUp(200, '2026-09-01', null, 'Qassim');
        $petty->issue($this->driver('Sohail'), 30, '2026-09-30', null, 'Mohmd');
        $petty->topUp(999, '2026-10-01', null, 'Qassim');

        $sep = $petty->statement(\Illuminate\Support\Carbon::parse('2026-09-01'), \Illuminate\Support\Carbon::parse('2026-09-30'));

        $this->assertEqualsWithDelta(180.0, $sep['opening'], 0.001);
        $this->assertEqualsWithDelta(200.0, $sep['in'], 0.001);
        $this->assertEqualsWithDelta(30.0, $sep['out'], 0.001);
        $this->assertEqualsWithDelta(350.0, $sep['closing'], 0.001);
        $this->assertCount(2, $sep['rows']);
    }

    public function test_the_float_card_opens_the_full_details(): void
    {
        $this->asManager();
        $petty = app(PettyCashService::class);
        $petty->topUp(250, now()->format('Y-m-d'), 'From the safe', 'Qassim');
        $petty->issue($this->driver('Hassan'), 40, now()->format('Y-m-d'), null, 'Mohmd');

        Livewire::test(PettyCash::class)
            ->assertSee('Full details')
            ->call('$set', 'tab', 'float')
            ->assertSee('How the float balance adds up')
            ->assertSee('From the safe')
            ->assertSee('Given to driver')
            ->assertSee('Hassan')
            ->assertSee('210.00')
            ->call('$set', 'floatMonth', 'all')
            ->assertSee('All time')
            ->call('shiftFloatMonth', -1)
            ->assertSet('floatMonth', now()->format('Y-m'))
            ->call('shiftFloatMonth', -1)
            ->assertSet('floatMonth', now()->subMonthNoOverflow()->format('Y-m'))
            ->assertSee('No money moved in or out of the float in this period.');
    }

    /** Receipts settled from petty cash carry their driver, for the Driver filter in Expenses. */
    public function test_settling_files_the_receipts_under_their_driver(): void
    {
        $this->asAccountant();
        $driver = \Modules\Limousine\Models\LimoDriver::query()->create(['name' => 'Sam']);
        $petty = app(\Modules\Limousine\Services\PettyCash::class);
        $petty->topUp(100, now()->toDateString(), null, 'Owner');
        $advance = $petty->issue($driver, 30, now()->toDateString(), null, 'Abbas');
        $this->assertNotNull($advance);
        $advance->lines()->create(['date' => now()->toDateString(), 'category' => 'Fuel', 'amount' => 30]);
        $petty->confirm($advance, 'Amal');
        $petty->settle($advance->fresh() ?? $advance, 'Amal');

        $this->assertSame($driver->id, \Modules\Limousine\Models\LimoExpense::query()->firstOrFail()->driver_id);
    }
}
