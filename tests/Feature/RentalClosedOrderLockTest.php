<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Rental\Livewire\OrderForm;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * A closed order is locked: its details can only be edited by a super-admin.
 * Everyone else sees a read-only form (no Save button) and a server-side guard
 * refuses a save even if the request is forged.
 */
final class RentalClosedOrderLockTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('rental');
    }

    private function closedOrder(): RentalOrder
    {
        return RentalOrder::query()->create([
            'customer_id' => RentalCustomer::query()->create(['name' => 'Ali'])->id,
            'vehicle_id' => Vehicle::query()->create(['name' => 'Yaris', 'daily_rate' => 10])->id,
            'start_date' => Carbon::now()->subDays(3),
            'end_date' => Carbon::now()->subDays(1),
            'rate_type' => 'daily', 'rate' => 10, 'hired_time' => '10:00',
            'state' => RentalOrder::STATE_CLOSED, 'returned_at' => Carbon::now()->subDays(1),
        ]);
    }

    public function test_a_regular_admin_sees_the_closed_order_locked(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => false]));
        $order = $this->closedOrder();

        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->assertViewHas('locked', true)
            ->assertSee('only a super-admin can edit');
    }

    public function test_a_regular_admin_cannot_save_a_closed_order(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => false]));
        $order = $this->closedOrder();

        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->set('rate', '999')
            ->call('save')
            ->assertForbidden();

        $this->assertSame(10.0, $order->fresh()?->rate);
    }

    public function test_a_super_admin_is_not_locked_and_can_save(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => true]));
        $order = $this->closedOrder();

        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->assertViewHas('locked', false)
            ->set('rate', '15')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(15.0, $order->fresh()?->rate);
    }

    public function test_a_draft_order_is_not_locked_for_a_regular_admin(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => false]));
        $order = $this->closedOrder();
        $order->forceFill(['state' => RentalOrder::STATE_DRAFT, 'returned_at' => null])->save();

        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->assertViewHas('locked', false);
    }
}
