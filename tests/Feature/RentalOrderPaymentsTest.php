<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Livewire\Pages\DailySummary;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Rental\Livewire\OrderForm;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\RentalOrderPayment;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * Every change to a rental order's Advance leaves a dated line, so the Daily
 * Summary can put Rent A Car money on the day it came in. Coupon credit is
 * recorded but never counted as money; imports leave no lines.
 */
final class RentalOrderPaymentsTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('rental');
        $this->grantEveryone('rental.order');
        $this->actingAs(User::factory()->create(['is_admin' => true, 'name' => 'Huda']));
    }

    private function order(float $advance = 0, ?string $type = 'cash'): RentalOrder
    {
        return RentalOrder::query()->create([
            'customer_id' => RentalCustomer::query()->create(['name' => 'Ali'])->id,
            'vehicle_id' => Vehicle::query()->create(['name' => 'Yaris', 'daily_rate' => 10])->id,
            'start_date' => Carbon::now()->addDay(),
            'end_date' => Carbon::now()->addDays(6),
            'rate_type' => 'daily',
            'rate' => 10,
            'advance_amount' => $advance,
            'payment_type' => $type,
        ]);
    }

    public function test_each_advance_change_is_dated_with_method_and_who(): void
    {
        Carbon::setTestNow('2026-11-20 10:00');
        $order = $this->order(20);

        Carbon::setTestNow('2026-11-22 10:00');
        $order->advance_amount = 55;
        $order->payment_type = 'credit_card';
        $order->save();

        Carbon::setTestNow('2026-11-23 10:00');
        $order->advance_amount = 50;
        $order->save();
        $order->notes = 'no money change';
        $order->save();

        $rows = RentalOrderPayment::query()->orderBy('id')->get();
        $this->assertCount(3, $rows);
        $this->assertSame(['2026-11-20', 20.0, 'payment', 'cash', 'Huda'], [$rows[0]->date?->toDateString(), $rows[0]->amount, $rows[0]->kind, $rows[0]->method, $rows[0]->taken_by]);
        $this->assertSame(['2026-11-22', 35.0, 'payment', 'credit_card'], [$rows[1]->date?->toDateString(), $rows[1]->amount, $rows[1]->kind, $rows[1]->method]);
        $this->assertSame(['2026-11-23', -5.0, 'correction'], [$rows[2]->date?->toDateString(), $rows[2]->amount, $rows[2]->kind]);
        $this->assertSame(35.0, RentalOrderPayment::moneyOn('2026-11-22'));
    }

    public function test_coupon_credit_is_recorded_but_not_money(): void
    {
        $order = $this->order();
        $order->applyCouponCredit(15);

        $row = RentalOrderPayment::query()->sole();
        $this->assertSame(['credit', 'coupon', 15.0], [$row->kind, $row->method, $row->amount]);
        $this->assertSame(0.0, RentalOrderPayment::moneyOn(Carbon::today()->toDateString()));
    }

    public function test_imports_and_quiet_corrections_write_no_payments(): void
    {
        RentalOrder::withoutPaymentLog(fn () => $this->order(100));
        $order = $this->order();
        $order->advance_amount = 30;
        $order->saveQuietly();

        $this->assertSame(0, RentalOrderPayment::query()->count());
    }

    public function test_the_daily_summary_counts_rental_order_money_and_the_form_lists_it(): void
    {
        app(ModuleManager::class)->install('purchases');
        $order = $this->order(40);
        $order->applyCouponCredit(10);

        Livewire::test(DailySummary::class)
            ->assertViewHas('today', fn (array $t): bool => $t['rental'] === 40.0);

        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->assertSeeHtml('data-order-payments')
            ->assertSee('Coupon credit (not money)')
            ->assertSee('Huda');
    }
}
