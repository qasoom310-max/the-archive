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
 * under the amount → a salary deduction; over it → reimbursed from the float.
 * Both snapshotted, because what was decided about a man's pay must not move
 * when a line is edited later.
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

    public function test_receipts_short_of_the_amount_become_a_salary_deduction(): void
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
            ->call('openSettle')
            ->call('saveSettle')
            ->assertHasNoErrors();

        $fresh = $advance->fresh();
        $this->assertSame(LimoPettyAdvance::STATUS_CLEARED, $fresh?->status);
        $this->assertEqualsWithDelta(84.0, $fresh?->receipts_total, 0.001);
        // He was given 100 and can show 84 — the 16 comes off his salary.
        $this->assertEqualsWithDelta(16.0, $fresh?->shortfall, 0.001);
        $this->assertEqualsWithDelta(0.0, $fresh?->excess, 0.001);
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

    public function test_the_month_report_names_the_deductions_for_payroll(): void
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
            ->assertSee(__('Salary deductions this month'))
            ->assertDontSee(__('No deductions — every driver accounted for his money.'));
    }
}
