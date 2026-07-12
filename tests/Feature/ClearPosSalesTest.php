<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Erp\Tenancy\WorkspaceManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosPaymentMethod;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosSession;
use Tests\TestCase;

/**
 * The pos:clear-sales maintenance command — wipes finalized (test) sales from a
 * workspace while leaving sessions and stock alone. Dry-run unless --confirm.
 */
final class ClearPosSalesTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('pos');
    }

    private function seedSale(): array
    {
        $session = PosSession::query()->create([
            'reference' => 'POS-S/0001',
            'state' => SessionState::Opened,
            'opening_cash' => 0.0,
            'opened_at' => now(),
        ]);
        $method = PosPaymentMethod::query()->create(['name' => 'Cash', 'is_cash' => true, 'sequence' => 1]);
        $product = PosProduct::query()->create(['name' => 'Perfume', 'price' => 20, 'tax_rate' => 0, 'active' => true, 'stock_on_hand' => 5]);

        $order = PosOrder::query()->create([
            'reference' => 'POS/1/0001',
            'pos_session_id' => $session->id,
            'state' => OrderState::Done->value,
            'total' => 20,
            'ordered_at' => now(),
        ]);
        $order->lines()->create(['pos_product_id' => $product->id, 'name' => 'Perfume', 'qty' => 1, 'unit_price' => 20, 'discount' => 0, 'tax_rate' => 0]);
        $order->payments()->create(['pos_payment_method_id' => $method->id, 'amount' => 20, 'paid_at' => now()]);

        return [$session, $product, $order];
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        [, , $order] = $this->seedSale();
        $mainId = app(WorkspaceManager::class)->ensureMain()->id;

        $this->artisan('pos:clear-sales', ['--workspace' => $mainId])->assertExitCode(0);

        $this->assertDatabaseHas('pos_orders', ['id' => $order->id]);
    }

    public function test_confirm_deletes_the_sale_but_keeps_the_session_and_stock(): void
    {
        [$session, $product, $order] = $this->seedSale();
        $mainId = app(WorkspaceManager::class)->ensureMain()->id;

        $this->artisan('pos:clear-sales', ['--workspace' => $mainId, '--confirm' => true])->assertExitCode(0);

        $this->assertDatabaseMissing('pos_orders', ['id' => $order->id]);
        $this->assertDatabaseMissing('pos_payments', ['pos_order_id' => $order->id]);
        $this->assertDatabaseMissing('pos_order_lines', ['pos_order_id' => $order->id]);
        // Sessions and stock are deliberately left untouched.
        $this->assertDatabaseHas('pos_sessions', ['id' => $session->id]);
        $this->assertSame(5.0, (float) $product->fresh()?->stock_on_hand);
    }

    public function test_the_date_window_scopes_what_is_deleted(): void
    {
        [, , $order] = $this->seedSale();
        $mainId = app(WorkspaceManager::class)->ensureMain()->id;

        // A window that excludes today's sale leaves it in place.
        $this->artisan('pos:clear-sales', [
            '--workspace' => $mainId,
            '--from' => '2000-01-01',
            '--to' => '2000-01-02',
            '--confirm' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('pos_orders', ['id' => $order->id]);
    }
}
