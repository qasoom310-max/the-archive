<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Livewire\Views\ListView;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Accounting\Models\JournalEntry;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosPaymentMethod;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosSession;
use Modules\Pos\Services\PosSaleEraser;
use Tests\TestCase;

/**
 * Deleting a POS order must always unwind the journal entries it created — no
 * matter which screen or service does the deleting.
 *
 * Regression (found 2026-08-09, after live figures drifted on the Kaleem
 * workspace): the ledger cleanup lived only inside `PosSaleEraser`, so it ran
 * only for the POS Orders page. The engine's generic `ListView` bulk delete ran
 * a query-builder mass delete instead, which removes rows without firing model
 * events — orders vanished while every `POS/...` and `DEL/POS/...` journal entry
 * stayed behind, and Accounting kept reporting revenue for sales that no longer
 * existed. Nothing errored, so it went unnoticed across more than one deletion.
 *
 * The cleanup now hangs off `PosOrder`'s `deleting` event and `ListView` deletes
 * model-by-model, so every path is covered. These tests pin both halves: remove
 * either and one of them fails.
 */
final class PosOrderDeletionCleanupTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('pos');
        app(ModuleManager::class)->install('accounting');
    }

    /**
     * A finalized sale plus the two journal entries a POS sale posts: the sale
     * itself (keyed on the order reference) and the delivery-cost entry that
     * remote orders add as "DEL/<reference>".
     */
    private function seedSaleWithLedger(string $reference = 'POS/1/0001'): PosOrder
    {
        $session = PosSession::query()->create([
            'reference' => 'POS-S/0001',
            'state' => SessionState::Opened,
            'opening_cash' => 0.0,
            'opened_at' => now(),
        ]);
        $method = PosPaymentMethod::query()->create(['name' => 'Cash', 'is_cash' => true, 'sequence' => 1]);
        $product = PosProduct::query()->create([
            'name' => 'Perfume', 'price' => 20, 'tax_rate' => 0, 'active' => true, 'stock_on_hand' => 5,
        ]);

        $order = PosOrder::query()->create([
            'reference' => $reference,
            'pos_session_id' => $session->id,
            'state' => OrderState::Done->value,
            'total' => 20,
            'ordered_at' => now(),
        ]);
        $order->lines()->create([
            'pos_product_id' => $product->id, 'name' => 'Perfume',
            'qty' => 1, 'unit_price' => 20, 'discount' => 0, 'tax_rate' => 0,
        ]);
        $order->payments()->create([
            'pos_payment_method_id' => $method->id, 'amount' => 20, 'paid_at' => now(),
        ]);

        foreach ([$reference, 'DEL/' . $reference] as $i => $ref) {
            JournalEntry::query()->create([
                'number' => sprintf('SALE/2026/%04d', $i + 1),
                'date' => now()->toDateString(),
                'reference' => $ref,
                'state' => 'posted',
            ]);
        }

        return $order;
    }

    private function ledgerCountFor(string $reference): int
    {
        return JournalEntry::query()
            ->whereIn('reference', [$reference, 'DEL/' . $reference])
            ->count();
    }

    public function test_the_generic_list_bulk_delete_also_clears_the_journal_entries(): void
    {
        $order = $this->seedSaleWithLedger();
        $this->assertSame(2, $this->ledgerCountFor('POS/1/0001'));

        Livewire::test(ListView::class, ['model' => PosOrder::class, 'modelKey' => 'pos.order'])
            ->set('selected', [$order->id])
            ->call('bulkDelete');

        $this->assertDatabaseMissing('pos_orders', ['id' => $order->id]);
        $this->assertSame(
            0,
            $this->ledgerCountFor('POS/1/0001'),
            'Bulk-deleting from the generic list must not leave journal entries behind.',
        );
    }

    public function test_deleting_the_model_directly_clears_the_journal_entries(): void
    {
        // The guarantee that makes the fix structural rather than per-screen:
        // any future code path that deletes an order through Eloquent inherits
        // the cleanup without having to know the service exists.
        $order = $this->seedSaleWithLedger();

        $order->delete();

        $this->assertSame(0, $this->ledgerCountFor('POS/1/0001'));
        $this->assertDatabaseMissing('pos_payments', ['pos_order_id' => $order->id]);
        $this->assertDatabaseMissing('pos_order_lines', ['pos_order_id' => $order->id]);
    }

    public function test_the_sale_eraser_still_clears_everything(): void
    {
        $order = $this->seedSaleWithLedger();

        app(PosSaleEraser::class)->erase($order);

        $this->assertDatabaseMissing('pos_orders', ['id' => $order->id]);
        $this->assertSame(0, $this->ledgerCountFor('POS/1/0001'));
    }

    public function test_only_the_deleted_orders_ledger_is_touched(): void
    {
        $doomed = $this->seedSaleWithLedger('POS/1/0001');
        JournalEntry::query()->create([
            'number' => 'SALE/2026/0099',
            'date' => now()->toDateString(),
            'reference' => 'POS/1/0002',
            'state' => 'posted',
        ]);

        $doomed->delete();

        $this->assertDatabaseHas('journal_entries', ['reference' => 'POS/1/0002']);
    }
}
