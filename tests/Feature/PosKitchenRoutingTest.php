<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Event;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\PrepStation;
use Modules\Pos\Enums\PrepStatus;
use Modules\Pos\Events\PosOrderPaid;
use Modules\Pos\Listeners\QueueLinesForKitchen;
use Modules\Pos\Livewire\KitchenDisplay;
use Modules\Pos\Models\PosCategory;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosOrderLine;
use Modules\Pos\Models\PosPaymentMethod;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Services\PosSessionManager;
use Tests\TestCase;

/**
 * End-to-end: a sale finalised through `PosOrder::finalizeSale()` must
 * stamp `prep_status = pending` on every line whose product's category
 * has a `station` set, and leave lines whose category has no station
 * untouched. Pinned because the listener path is silent in production
 * (errors are swallowed and the order still saves), so a regression
 * here would only surface as "the KDS screens are empty" — exactly
 * the symptom that prompted this test.
 */
final class PosKitchenRoutingTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function installPos(): void
    {
        app(ModuleManager::class)->install('pos');

        // Module providers boot at app startup; mid-test install skips
        // PosServiceProvider::boot(), so the KDS listener isn't wired
        // unless we mirror it here. Matches the workaround used by
        // `PosWhatsAppReceiptTest` (see `module-routes-need-web-group`).
        Event::listen(PosOrderPaid::class, [QueueLinesForKitchen::class, 'handle']);
    }

    public function test_finalize_sale_stamps_prep_status_only_on_routed_lines(): void
    {
        $this->installPos();

        $admin = User::query()->where('is_admin', true)->sole();

        $kitchenCat = PosCategory::query()->create(['name' => 'Hot Drinks', 'station' => 'kitchen']);
        $shishaCat = PosCategory::query()->create(['name' => 'Shisha Bar', 'station' => 'shisha']);
        $noStationCat = PosCategory::query()->create(['name' => 'Bottled', 'station' => null]);

        $espresso = PosProduct::query()->create([
            'name' => 'Espresso', 'price' => 3.0, 'tax_rate' => 0.0,
            'cost_price' => 1.0, 'pos_category_id' => $kitchenCat->id, 'active' => true,
        ]);
        $shisha = PosProduct::query()->create([
            'name' => 'Apple Shisha', 'price' => 10.0, 'tax_rate' => 0.0,
            'cost_price' => 2.0, 'pos_category_id' => $shishaCat->id, 'active' => true,
        ]);
        $water = PosProduct::query()->create([
            'name' => 'Water', 'price' => 1.0, 'tax_rate' => 0.0,
            'cost_price' => 0.5, 'pos_category_id' => $noStationCat->id, 'active' => true,
        ]);

        $cash = PosPaymentMethod::query()->create(['name' => 'Cash', 'code' => 'cash', 'active' => true]);

        $session = app(PosSessionManager::class)->openOrResume(0.0, $admin->id);

        $order = PosOrder::query()->create([
            'pos_session_id' => $session->id,
            'reference' => 'POS/' . $session->id . '/0001',
            'state' => OrderState::Draft,
            'user_id' => $admin->id,
            'subtotal' => 14.0, 'tax_total' => 0.0, 'total' => 14.0,
        ]);

        foreach ([$espresso, $shisha, $water] as $p) {
            PosOrderLine::query()->create([
                'pos_order_id' => $order->id,
                'pos_product_id' => $p->id,
                'name' => $p->name,
                'qty' => 1,
                'unit_price' => $p->price,
                'tax_rate' => 0.0,
                'discount_pct' => 0.0,
                'subtotal' => $p->price,
                'tax_amount' => 0.0,
                'total' => $p->price,
            ]);
        }

        $order->payments()->create(['pos_payment_method_id' => $cash->id, 'amount' => 14.0]);

        // The bug this test pins: the listener used to TypeError because
        // pluck('station') on the Eloquent builder runs the PrepStation
        // Attribute accessor, returning an enum instance from a closure
        // typed `?string`. The fix routes plucks through the base query
        // builder so values stay scalar.
        $order->finalizeSale();

        $byName = PosOrderLine::query()->get()->keyBy('name');

        $this->assertSame(PrepStatus::Pending, $byName['Espresso']->prep_status);
        $this->assertSame(PrepStatus::Pending, $byName['Apple Shisha']->prep_status);
        $this->assertNull($byName['Water']->prep_status);

        $this->assertNotNull($byName['Espresso']->prep_sent_at);
        $this->assertNotNull($byName['Apple Shisha']->prep_sent_at);
        $this->assertNull($byName['Water']->prep_sent_at);
    }

    /**
     * Regression: the Pending column's "Start preparing" button used to
     * call `markOrderReady`, which loops `advancePrep()` until every line
     * is Ready — so Pending jumped straight past Preparing. The Pending
     * → Preparing transition must move exactly one step and stamp
     * `prep_started_at`.
     */
    public function test_mark_order_preparing_advances_pending_lines_exactly_one_step(): void
    {
        $this->installPos();

        $admin = User::query()->where('is_admin', true)->sole();

        $cat = PosCategory::query()->create(['name' => 'Hot Drinks', 'station' => 'kitchen']);
        $product = PosProduct::query()->create([
            'name' => 'Espresso', 'price' => 3.0, 'tax_rate' => 0.0,
            'cost_price' => 1.0, 'pos_category_id' => $cat->id, 'active' => true,
        ]);
        $cash = PosPaymentMethod::query()->create(['name' => 'Cash', 'code' => 'cash', 'active' => true]);

        $session = app(PosSessionManager::class)->openOrResume(0.0, $admin->id);
        $order = PosOrder::query()->create([
            'pos_session_id' => $session->id,
            'reference' => 'POS/' . $session->id . '/0001',
            'state' => OrderState::Draft,
            'user_id' => $admin->id,
            'subtotal' => 3.0, 'tax_total' => 0.0, 'total' => 3.0,
        ]);
        PosOrderLine::query()->create([
            'pos_order_id' => $order->id,
            'pos_product_id' => $product->id,
            'name' => 'Espresso',
            'qty' => 1,
            'unit_price' => 3.0, 'tax_rate' => 0.0, 'discount_pct' => 0.0,
            'subtotal' => 3.0, 'tax_amount' => 0.0, 'total' => 3.0,
        ]);
        $order->payments()->create(['pos_payment_method_id' => $cash->id, 'amount' => 3.0]);
        $order->finalizeSale();

        $this->assertSame(PrepStatus::Pending, PosOrderLine::query()->first()->prep_status);

        $kds = new KitchenDisplay();
        $kds->station = PrepStation::Kitchen;
        $kds->markOrderPreparing($order->id);

        $line = PosOrderLine::query()->first();
        $this->assertSame(PrepStatus::Preparing, $line->prep_status);
        $this->assertNotNull($line->prep_started_at);
        $this->assertNull($line->prep_ready_at);
    }

    public function test_listener_is_idempotent_on_double_dispatch(): void
    {
        $this->installPos();

        $admin = User::query()->where('is_admin', true)->sole();

        $cat = PosCategory::query()->create(['name' => 'Hot Drinks', 'station' => 'kitchen']);
        $product = PosProduct::query()->create([
            'name' => 'Espresso', 'price' => 3.0, 'tax_rate' => 0.0,
            'cost_price' => 1.0, 'pos_category_id' => $cat->id, 'active' => true,
        ]);
        $cash = PosPaymentMethod::query()->create(['name' => 'Cash', 'code' => 'cash', 'active' => true]);

        $session = app(PosSessionManager::class)->openOrResume(0.0, $admin->id);
        $order = PosOrder::query()->create([
            'pos_session_id' => $session->id,
            'reference' => 'POS/' . $session->id . '/0001',
            'state' => OrderState::Draft,
            'user_id' => $admin->id,
            'subtotal' => 3.0, 'tax_total' => 0.0, 'total' => 3.0,
        ]);
        PosOrderLine::query()->create([
            'pos_order_id' => $order->id,
            'pos_product_id' => $product->id,
            'name' => 'Espresso',
            'qty' => 1,
            'unit_price' => 3.0, 'tax_rate' => 0.0, 'discount_pct' => 0.0,
            'subtotal' => 3.0, 'tax_amount' => 0.0, 'total' => 3.0,
        ]);
        $order->payments()->create(['pos_payment_method_id' => $cash->id, 'amount' => 3.0]);

        $order->finalizeSale();
        $first = PosOrderLine::query()->first();
        $firstSentAt = $first->prep_sent_at;
        $first->prep_status = PrepStatus::Preparing;
        $first->save();

        // Re-dispatch — should NOT reset Preparing back to Pending.
        event(new PosOrderPaid($order));

        $line = PosOrderLine::query()->first();
        $this->assertSame(PrepStatus::Preparing, $line->prep_status);
        $this->assertEquals($firstSentAt?->toDateTimeString(), $line->prep_sent_at?->toDateTimeString());
    }
}
