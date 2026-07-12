<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Business\Feature;
use App\Erp\Business\Features;
use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Livewire\PosOrders;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosPaymentMethod;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosSession;
use Tests\TestCase;

/**
 * The POS Orders list: admin-only permanent delete (to clear test sales) and
 * the walk-in shop's Cashier + Time columns in place of Table + Type.
 */
final class PosOrdersAdminTest extends TestCase
{
    use DatabaseMigrations;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true, 'name' => 'Aisha Cashier']);
        $this->actingAs($this->admin);
        app(ModuleManager::class)->install('pos');
    }

    private function doneOrder(): PosOrder
    {
        $session = PosSession::query()->create([
            'reference' => 'POS-S/0001',
            'state' => SessionState::Opened,
            'opening_cash' => 0.0,
            'opened_at' => now(),
        ]);
        $method = PosPaymentMethod::query()->create(['name' => 'Cash', 'is_cash' => true, 'sequence' => 1]);
        $product = PosProduct::query()->create(['name' => 'Perfume', 'price' => 40, 'tax_rate' => 0, 'active' => true, 'stock_on_hand' => 5]);

        $order = PosOrder::query()->create([
            'reference' => 'POS/1/0001',
            'pos_session_id' => $session->id,
            'state' => OrderState::Done->value,
            'user_id' => $this->admin->id,
            'total' => 40,
            'ordered_at' => now(),
        ]);
        $order->lines()->create(['pos_product_id' => $product->id, 'name' => 'Perfume', 'qty' => 1, 'unit_price' => 40, 'discount' => 0, 'tax_rate' => 0]);
        $order->payments()->create(['pos_payment_method_id' => $method->id, 'amount' => 40, 'paid_at' => now()]);

        return $order;
    }

    public function test_an_admin_can_permanently_delete_an_order(): void
    {
        $order = $this->doneOrder();

        Livewire::test(PosOrders::class)->call('deleteOrder', $order->id);

        $this->assertDatabaseMissing('pos_orders', ['id' => $order->id]);
        $this->assertDatabaseMissing('pos_payments', ['pos_order_id' => $order->id]);
        $this->assertDatabaseMissing('pos_order_lines', ['pos_order_id' => $order->id]);
    }

    public function test_a_non_admin_cannot_delete_an_order(): void
    {
        $order = $this->doneOrder();
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        // Blocked either at the page's Read guard or the admin-only delete gate;
        // either way an authorization error is thrown and nothing is deleted.
        $deleted = false;
        try {
            Livewire::test(PosOrders::class)->call('deleteOrder', $order->id);
            $deleted = true;
        } catch (\Throwable) {
            // expected — forbidden
        }

        $this->assertFalse($deleted, 'A non-admin must not be able to delete an order.');
        $this->assertDatabaseHas('pos_orders', ['id' => $order->id]);
    }

    public function test_a_walk_in_shop_shows_cashier_and_time_instead_of_table_and_type(): void
    {
        $this->doneOrder();
        Features::setOverrides([Feature::Restaurant->value => false]);

        Livewire::test(PosOrders::class)
            ->assertSee('Cashier')
            ->assertSee('Time')
            ->assertSee('Aisha Cashier')
            ->assertDontSee('Walk-in');
    }

    public function test_a_dine_in_shop_keeps_table_and_type(): void
    {
        $this->doneOrder();
        Features::setOverrides([Feature::Restaurant->value => true]);

        Livewire::test(PosOrders::class)
            ->assertSee('Table')
            ->assertSee('Walk-in');
    }
}
