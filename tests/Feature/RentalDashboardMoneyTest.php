<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Rental\Livewire\RentalHome;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * The dashboard money box shows collected revenue and, folded into the same box,
 * how much is still owed across unpaid / part-paid orders.
 */
final class RentalDashboardMoneyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');
    }

    private function order(string $paymentStatus, float $total, float $balance, string $state = RentalOrder::STATE_ACTIVE): void
    {
        RentalOrder::query()->create([
            'customer_id' => RentalCustomer::query()->create(['name' => 'C'])->id,
            'vehicle_id' => Vehicle::query()->create(['name' => 'Yaris', 'daily_rate' => 10])->id,
            'start_date' => Carbon::now()->subDays(2), 'end_date' => Carbon::now()->subDay(),
            'rate_type' => 'daily', 'rate' => 10, 'total' => $total, 'balance' => $balance,
            'payment_status' => $paymentStatus, 'state' => $state,
        ]);
    }

    public function test_the_money_box_sums_the_outstanding_balance_of_owing_orders(): void
    {
        $this->order(RentalOrder::PAYMENT_UNPAID, 100, 100);          // owes 100
        $this->order(RentalOrder::PAYMENT_PARTIAL, 80, 30);          // owes 30 (part-paid)
        $this->order(RentalOrder::PAYMENT_PAID, 50, 0);              // fully paid → not owing

        Livewire::test(RentalHome::class)
            ->assertViewHas('unpaidOutstanding', 130.0)   // 100 + 30
            ->assertViewHas('unpaidOrders', 2)
            ->assertSee('Unpaid');
    }

    public function test_a_cancelled_unpaid_order_is_not_counted_as_owing(): void
    {
        $this->order(RentalOrder::PAYMENT_UNPAID, 100, 100, RentalOrder::STATE_CANCELLED);

        Livewire::test(RentalHome::class)
            ->assertViewHas('unpaidOutstanding', 0.0)
            ->assertViewHas('unpaidOrders', 0);
    }
}
