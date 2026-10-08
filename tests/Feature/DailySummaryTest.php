<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Livewire\Pages\DailySummary;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosSession;
use Modules\Purchases\Enums\PurchaseState;
use Modules\Purchases\Models\Purchase;
use Tests\TestCase;

/**
 * Daily Summary — the owner's cash-in vs cash-out view: sales (POS) minus
 * purchases (confirmed bills) = net, flagged red on a loss day. Admin-only.
 */
final class DailySummaryTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('purchases');
    }

    private function openSession(): PosSession
    {
        return PosSession::query()->create([
            'reference' => 'POS-S/0001',
            'state' => SessionState::Opened,
            'opening_cash' => 0,
            'opened_at' => now(),
        ]);
    }

    public function test_computes_sales_purchases_and_net_and_flags_profit(): void
    {
        $session = $this->openSession();
        // Two finalized sales today: 20 + 5 = 25.
        PosOrder::query()->create(['pos_session_id' => $session->id, 'reference' => 'POS/1', 'state' => OrderState::Done, 'total' => 20, 'ordered_at' => now()]);
        PosOrder::query()->create(['pos_session_id' => $session->id, 'reference' => 'POS/2', 'state' => OrderState::Done, 'total' => 5, 'ordered_at' => now()]);
        // A draft (unpaid) order must NOT count.
        PosOrder::query()->create(['pos_session_id' => $session->id, 'reference' => 'POS/3', 'state' => OrderState::Draft, 'total' => 99, 'ordered_at' => now()]);
        // A confirmed purchase today: 12. A draft bill must NOT count.
        Purchase::query()->create(['date' => Carbon::today()->toDateString(), 'state' => PurchaseState::Confirmed, 'total' => 12, 'is_stock_purchase' => true]);
        Purchase::query()->create(['date' => Carbon::today()->toDateString(), 'state' => PurchaseState::Draft, 'total' => 50, 'is_stock_purchase' => true]);

        Livewire::test(DailySummary::class)
            ->assertViewHas('today', fn (array $t): bool => $t['sales'] === 25.0 && $t['purchases'] === 12.0 && $t['net'] === 13.0)
            ->assertSee('Cash positive');
    }

    public function test_flags_a_loss_day_in_red(): void
    {
        $session = $this->openSession();
        PosOrder::query()->create(['pos_session_id' => $session->id, 'reference' => 'POS/1', 'state' => OrderState::Done, 'total' => 5, 'ordered_at' => now()]);
        Purchase::query()->create(['date' => Carbon::today()->toDateString(), 'state' => PurchaseState::Confirmed, 'total' => 12, 'is_stock_purchase' => true]);

        Livewire::test(DailySummary::class)
            ->assertViewHas('today', fn (array $t): bool => $t['net'] === -7.0)
            ->assertSee('In the red');
    }

    /**
     * Sweileh trades past midnight and counts its day 8 AM to 8 AM: a 2 AM
     * sale belongs to the evening before, not to a day that has not opened.
     */
    public function test_a_day_starting_at_8_counts_after_midnight_sales_for_the_evening_before(): void
    {
        \App\Erp\Settings\Setting::set('company.day_starts_at', '8');
        Carbon::setTestNow('2026-10-08 11:09:00');
        $session = $this->openSession();
        PosOrder::query()->create(['pos_session_id' => $session->id, 'reference' => 'POS/1', 'state' => OrderState::Done, 'total' => 28.45, 'ordered_at' => '2026-10-08 02:00:00']);
        PosOrder::query()->create(['pos_session_id' => $session->id, 'reference' => 'POS/2', 'state' => OrderState::Done, 'total' => 10, 'ordered_at' => '2026-10-07 20:00:00']);
        PosOrder::query()->create(['pos_session_id' => $session->id, 'reference' => 'POS/3', 'state' => OrderState::Done, 'total' => 4, 'ordered_at' => '2026-10-08 09:30:00']);

        Livewire::test(DailySummary::class)
            ->assertSet('date', '2026-10-08')
            ->assertViewHas('today', fn (array $t): bool => $t['sales'] === 4.0)
            ->set('date', '2026-10-07')
            ->assertViewHas('today', fn (array $t): bool => $t['sales'] === 38.45);

        Carbon::setTestNow();
    }

    /** Before 8 AM the day still running is yesterday's. */
    public function test_before_the_day_starts_it_opens_on_the_day_still_running(): void
    {
        \App\Erp\Settings\Setting::set('company.day_starts_at', '8');
        Carbon::setTestNow('2026-10-08 06:30:00');

        Livewire::test(DailySummary::class)->assertSet('date', '2026-10-07');

        Carbon::setTestNow();
    }

    /** Every other database keeps plain calendar days. */
    public function test_by_default_a_day_is_the_calendar_day(): void
    {
        Carbon::setTestNow('2026-10-08 11:09:00');
        $session = $this->openSession();
        PosOrder::query()->create(['pos_session_id' => $session->id, 'reference' => 'POS/1', 'state' => OrderState::Done, 'total' => 28.45, 'ordered_at' => '2026-10-08 02:00:00']);

        Livewire::test(DailySummary::class)
            ->assertViewHas('today', fn (array $t): bool => $t['sales'] === 28.45);

        Carbon::setTestNow();
    }

    public function test_non_admin_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        Livewire::test(DailySummary::class)->assertForbidden();
    }
}
