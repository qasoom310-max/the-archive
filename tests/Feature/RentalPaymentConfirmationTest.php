<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Livewire\Settings\UserManager;
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
 * Payment is derived from the advance (full → Paid, part → Partial, none →
 * Unpaid), and a Paid order is only trusted once an Accountant / super-admin
 * confirms it — never a regular admin.
 */
final class RentalPaymentConfirmationTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('rental');
    }

    /** A fully-paid order: 5 days × 10 = 50 + 10% VAT = 55, advance 55. */
    private function paidOrder(): RentalOrder
    {
        $order = RentalOrder::query()->create([
            'customer_id' => RentalCustomer::query()->create(['name' => 'Ali'])->id,
            'vehicle_id' => Vehicle::query()->create(['name' => 'Yaris', 'daily_rate' => 10])->id,
            'start_date' => Carbon::now()->addDay(),
            'end_date' => Carbon::now()->addDays(6),
            'rate_type' => 'daily',
            'rate' => 10,
            'advance_amount' => 55,
        ]);
        $order->recalcTotals();
        $order->save();

        return $order;
    }

    public function test_payment_status_follows_the_advance(): void
    {
        $order = $this->paidOrder();
        $this->assertSame(RentalOrder::PAYMENT_PAID, $order->payment_status);

        $order->advance_amount = 20;
        $order->recalcTotals();
        $this->assertSame(RentalOrder::PAYMENT_PARTIAL, $order->payment_status);

        $order->advance_amount = 0;
        $order->recalcTotals();
        $this->assertSame(RentalOrder::PAYMENT_UNPAID, $order->payment_status);
    }

    public function test_accountant_can_confirm_payment(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false, 'is_accountant' => true]));
        $order = $this->paidOrder();

        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->call('confirmPayment')
            ->assertHasNoErrors();

        $order->refresh();
        $this->assertTrue($order->payment_confirmed);
        $this->assertNotNull($order->confirmed_at);
    }

    public function test_super_admin_can_confirm_payment(): void
    {
        $super = User::factory()->create(['is_admin' => true, 'is_super_admin' => true]);
        $this->actingAs($super);
        $order = $this->paidOrder();

        Livewire::test(OrderForm::class, ['id' => $order->id])->call('confirmPayment');

        $this->assertTrue($order->fresh()?->payment_confirmed);
        $this->assertSame($super->id, $order->fresh()?->confirmed_by_user_id);
    }

    public function test_a_regular_admin_cannot_confirm_payment(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => false, 'is_accountant' => false]));
        $order = $this->paidOrder();

        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->call('confirmPayment')
            ->assertForbidden();

        $this->assertFalse($order->fresh()?->payment_confirmed);
    }

    public function test_confirmation_is_dropped_when_no_longer_fully_paid(): void
    {
        $this->actingAs(User::factory()->create(['is_accountant' => true]));
        $order = $this->paidOrder();

        Livewire::test(OrderForm::class, ['id' => $order->id])->call('confirmPayment');
        $this->assertTrue($order->fresh()?->payment_confirmed);

        // Total rises above the advance → no longer fully paid → confirmation cleared.
        $order->rate = 50;
        $order->recalcTotals();
        $order->save();

        $order->refresh();
        $this->assertSame(RentalOrder::PAYMENT_PARTIAL, $order->payment_status);
        $this->assertFalse($order->payment_confirmed);
    }

    public function test_only_a_super_admin_can_grant_the_accountant_role(): void
    {
        // Accountant is one of the ROLES in the edit form now (mutually
        // exclusive with Staff / Supervisor / Administrator / Super admin).
        $target = User::factory()->create();

        // A regular admin cannot assign it — the role isn't in their gift, so
        // validation rejects it.
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => false]));
        Livewire::test(UserManager::class)
            ->call('editUser', $target->id)
            ->set('role', 'accountant')
            ->call('save')
            ->assertHasErrors('role');
        $this->assertFalse($target->fresh()?->isAccountant());

        // A super-admin can.
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => true]));
        Livewire::test(UserManager::class)
            ->call('editUser', $target->id)
            ->set('role', 'accountant')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertTrue($target->fresh()?->isAccountant());
    }
}
