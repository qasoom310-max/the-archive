<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\Auth\ModelAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\PrepStatus;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Livewire\PosFloorPlan;
use Modules\Pos\Livewire\PosHome;
use Modules\Pos\Livewire\PosTerminal;
use Modules\Pos\Models\PosCategory;
use Modules\Pos\Models\PosFloor;
use Modules\Pos\Models\PosFloorLine;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosOrderLine;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosSession;
use Modules\Pos\Models\PosTable;
use Tests\TestCase;

/**
 * Restaurant table management: the cashier picks a table on the floor plan
 * before the terminal, and each table keeps its own running order. Walk-in
 * (no table) selling still works for shops with no tables configured.
 */
final class PosFloorTableTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('pos');
    }

    private function openSession(): PosSession
    {
        return PosSession::query()->create([
            'reference' => 'POS-S/0001',
            'state' => SessionState::Opened,
            'opening_cash' => 50.0,
            'opened_at' => now(),
        ]);
    }

    /** Reused across calls so multiple tables share ONE floor. (firstOrCreate
     *  by `name` can't be used — it's a translatable JSON column, so a plain
     *  where('name', 'Main floor') never matches the {"en":…} envelope and would
     *  silently create a fresh floor every call.) */
    private ?int $mainFloorId = null;

    private function table(int $seats = 4, string $name = '1'): PosTable
    {
        $this->mainFloorId ??= (int) PosFloor::query()->create(['name' => 'Main floor', 'sequence' => 10])->id;

        return PosTable::query()->create([
            'pos_floor_id' => $this->mainFloorId,
            'name' => $name,
            'seats' => $seats,
            'shape' => 'square',
        ]);
    }

    public function test_open_routes_to_the_floor_plan_when_tables_exist(): void
    {
        $this->table();

        Livewire::test(PosHome::class)->set('openingCash', '0')->call('openSession');
        $session = PosSession::query()->where('state', SessionState::Opened)->firstOrFail();

        Livewire::test(PosHome::class)
            ->set('openingCash', '0')
            ->call('openSession')
            ->assertRedirect(url('/app/pos/session/' . $session->id . '/floor'));
    }

    public function test_open_routes_straight_to_the_terminal_with_no_tables(): void
    {
        Livewire::test(PosHome::class)->set('openingCash', '0')->call('openSession');
        $session = PosSession::query()->where('state', SessionState::Opened)->firstOrFail();

        Livewire::test(PosHome::class)
            ->set('openingCash', '0')
            ->call('openSession')
            ->assertRedirect(url('/app/pos/session/' . $session->id . '/terminal'));
    }

    public function test_selecting_a_table_binds_the_draft_order_to_it(): void
    {
        $session = $this->openSession();
        $table = $this->table();

        Livewire::test(PosTerminal::class, ['session' => $session->id, 'table' => $table->id]);

        $order = PosOrder::query()
            ->where('pos_session_id', $session->id)
            ->where('pos_table_id', $table->id)
            ->firstOrFail();

        $this->assertNotNull($order);
    }

    public function test_each_table_keeps_its_own_order_and_walkin_is_separate(): void
    {
        $session = $this->openSession();
        $t1 = $this->table(name: '1');
        $t2 = $this->table(name: '2');

        Livewire::test(PosTerminal::class, ['session' => $session->id, 'table' => $t1->id]);
        Livewire::test(PosTerminal::class, ['session' => $session->id, 'table' => $t2->id]);
        Livewire::test(PosTerminal::class, ['session' => $session->id]); // walk-in

        $this->assertSame(1, PosOrder::query()->where('pos_table_id', $t1->id)->count());
        $this->assertSame(1, PosOrder::query()->where('pos_table_id', $t2->id)->count());
        $this->assertSame(1, PosOrder::query()->whereNull('pos_table_id')->count());
    }

    public function test_adding_a_kitchen_item_auto_sends_it_to_the_kitchen(): void
    {
        $session = $this->openSession();
        $table = $this->table();
        $cat = PosCategory::query()->create(['name' => 'Kitchen', 'station' => 'kitchen']);
        $burger = PosProduct::query()->create([
            'name' => 'Burger', 'price' => 5.0, 'tax_rate' => 0.0,
            'active' => true, 'pos_category_id' => $cat->id,
        ]);
        // A category with no station is NOT routed.
        $drinks = PosCategory::query()->create(['name' => 'Drinks', 'station' => null]);
        $water = PosProduct::query()->create([
            'name' => 'Water', 'price' => 1.0, 'tax_rate' => 0.0,
            'active' => true, 'pos_category_id' => $drinks->id,
        ]);

        Livewire::test(PosTerminal::class, ['session' => $session->id, 'table' => $table->id])
            ->call('addProduct', $burger->id)
            ->call('addProduct', $water->id);

        $burgerLine = PosOrderLine::query()->where('pos_product_id', $burger->id)->firstOrFail();
        $waterLine = PosOrderLine::query()->where('pos_product_id', $water->id)->firstOrFail();

        // Kitchen item went to the KDS immediately — no payment needed.
        $this->assertSame(PrepStatus::Pending, $burgerLine->prep_status);
        $this->assertNotNull($burgerLine->prep_sent_at);
        // No-station item is never routed.
        $this->assertNull($waterLine->prep_status);
    }

    public function test_floor_plan_colours_a_table_by_its_kitchen_status(): void
    {
        $session = $this->openSession();
        $table = $this->table(name: '7');
        $cat = PosCategory::query()->create(['name' => 'Kitchen', 'station' => 'kitchen']);
        $burger = PosProduct::query()->create([
            'name' => 'Burger', 'price' => 5.0, 'tax_rate' => 0.0,
            'active' => true, 'pos_category_id' => $cat->id,
        ]);

        // Add a kitchen item → auto-sent, pending → red.
        Livewire::test(PosTerminal::class, ['session' => $session->id, 'table' => $table->id])
            ->call('addProduct', $burger->id);

        $floor = fn () => Livewire::test(PosFloorPlan::class, ['session' => $session->id])
            ->call('selectFloor', $table->pos_floor_id);

        // Card-specific class (bg + text colour) so the colour legend's bare
        // swatch classes can't satisfy the assertion.
        $floor()->assertSee('bg-red-500 text-white');

        // Cook starts it → preparing → yellow.
        $line = PosOrderLine::query()->firstOrFail();
        $line->prep_status = PrepStatus::Preparing;
        $line->save();
        $floor()->assertSee('bg-amber-400 text-chrome-900');

        // Cook finishes → ready → green (awaiting payment).
        $line->prep_status = PrepStatus::Ready;
        $line->save();
        $floor()->assertSee('bg-emerald-500 text-white');
    }

    public function test_dine_in_payment_is_gated_until_the_kitchen_is_ready(): void
    {
        $session = $this->openSession();
        $table = $this->table();
        $cat = PosCategory::query()->create(['name' => 'Kitchen', 'station' => 'kitchen']);
        $burger = PosProduct::query()->create([
            'name' => 'Burger', 'price' => 5.0, 'tax_rate' => 0.0,
            'active' => true, 'pos_category_id' => $cat->id,
        ]);

        // Add a kitchen item → auto-sent, pending. "Pay now" must stay locked.
        $term = Livewire::test(PosTerminal::class, ['session' => $session->id, 'table' => $table->id])
            ->call('addProduct', $burger->id)
            ->call('startPayment')
            ->assertSet('paying', false);

        // Cook marks it ready → green → payment unlocks.
        $line = PosOrderLine::query()->firstOrFail();
        $line->prep_status = PrepStatus::Ready;
        $line->save();

        $term->call('startPayment')->assertSet('paying', true);
    }

    public function test_walk_in_payment_is_not_gated_by_the_kitchen(): void
    {
        $session = $this->openSession();
        $cat = PosCategory::query()->create(['name' => 'Kitchen', 'station' => 'kitchen']);
        $burger = PosProduct::query()->create([
            'name' => 'Burger', 'price' => 5.0, 'tax_rate' => 0.0,
            'active' => true, 'pos_category_id' => $cat->id,
        ]);

        // Walk-in / quick sale (no table) pays immediately — counter service,
        // even though the item is pending in the kitchen.
        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('addProduct', $burger->id)
            ->call('startPayment')
            ->assertSet('paying', true);
    }

    public function test_an_empty_draft_leaves_the_table_white(): void
    {
        $session = $this->openSession();
        $table = $this->table(name: '7');

        // A draft with NO lines — the state a table is left in after its order
        // was paid and a fresh (empty) order was opened. It must read as free
        // (white), not green, so a paid table visibly clears.
        PosOrder::query()->create([
            'pos_session_id' => $session->id,
            'pos_table_id' => $table->id,
            'reference' => 'POS/' . $session->id . '/0001',
            'state' => OrderState::Draft,
        ]);

        Livewire::test(PosFloorPlan::class, ['session' => $session->id])
            ->call('selectFloor', $table->pos_floor_id)
            ->assertSee('7')
            ->assertDontSee('bg-emerald-500 text-white'); // empty draft = white
    }

    public function test_new_order_from_a_table_receipt_returns_to_the_floor(): void
    {
        $session = $this->openSession();
        $table = $this->table();

        Livewire::test(PosTerminal::class, ['session' => $session->id, 'table' => $table->id])
            ->call('finishToFloor')
            ->assertRedirect(url('/app/pos/session/' . $session->id . '/floor'));
    }

    public function test_new_order_from_a_walk_in_receipt_stays_in_place(): void
    {
        $session = $this->openSession();

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('finishToFloor')
            ->assertNoRedirect();
    }

    public function test_unknown_table_404s(): void
    {
        $session = $this->openSession();

        Livewire::test(PosTerminal::class, ['session' => $session->id, 'table' => 9999])
            ->assertStatus(404);
    }

    public function test_floor_plan_lists_tables_and_marks_an_occupied_one(): void
    {
        $session = $this->openSession();
        $table = $this->table(seats: 4, name: '7');
        // A drinks-only product (no station) — added items occupy the table
        // but route nothing to the kitchen, so the table reads green (ready /
        // awaiting payment), not red.
        $product = PosProduct::query()->create(['name' => 'Latte', 'price' => 3.0, 'tax_rate' => 0.0, 'active' => true]);

        // Empty floor first: the table shows by name and is free (white).
        // Select the table's floor explicitly so default-floor seeding can't
        // shadow it.
        Livewire::test(PosFloorPlan::class, ['session' => $session->id])
            ->call('selectFloor', $table->pos_floor_id)
            ->assertSee('7')
            ->assertDontSee('bg-emerald-500 text-white'); // no occupied card yet

        // Add a product → the table is now occupied. No kitchen routing, so
        // it's green (awaiting payment).
        Livewire::test(PosTerminal::class, ['session' => $session->id, 'table' => $table->id])
            ->call('addProduct', $product->id);

        Livewire::test(PosFloorPlan::class, ['session' => $session->id])
            ->call('selectFloor', $table->pos_floor_id)
            ->assertSee('bg-emerald-500 text-white');
    }

    public function test_arranging_saves_a_table_position(): void
    {
        $session = $this->openSession();
        $table = $this->table(name: '5');

        Livewire::test(PosFloorPlan::class, ['session' => $session->id])
            ->call('selectFloor', $table->pos_floor_id)
            ->assertSet('canEdit', true) // acting admin
            ->call('toggleEditing')
            ->assertSet('editing', true)
            ->call('moveTable', $table->id, 3, 2);

        $fresh = $table->fresh();
        $this->assertSame(3, $fresh?->pos_x);
        $this->assertSame(2, $fresh?->pos_y);
    }

    public function test_select_then_place_seats_a_table_in_a_cell(): void
    {
        $session = $this->openSession();
        $table = $this->table(name: '5');

        // Pick up the table, then click cell (2, 1)'s circle.
        Livewire::test(PosFloorPlan::class, ['session' => $session->id])
            ->call('selectFloor', $table->pos_floor_id)
            ->call('selectTable', $table->id)
            ->assertSet('selectedId', $table->id)
            ->call('placeAt', 2, 1)
            ->assertSet('selectedId', null); // selection cleared after placing

        // CELL 96, centred offset (96-84)/2 = 6 → col*96+6, row*96+6.
        $fresh = $table->fresh();
        $this->assertSame(2 * 96 + 6, $fresh?->pos_x);
        $this->assertSame(1 * 96 + 6, $fresh?->pos_y);
    }

    public function test_off_grid_table_still_renders_inside_the_fixed_grid(): void
    {
        $session = $this->openSession();
        $table = $this->table(name: '8');
        // Way off-grid (old free-drag data) — must be clamped into the grid and
        // still rendered as a placed cell, never lost off-canvas.
        $table->update(['pos_x' => 5000, 'pos_y' => 5000]);

        Livewire::test(PosFloorPlan::class, ['session' => $session->id])
            ->call('selectFloor', $table->pos_floor_id)
            ->assertSee('wire:key="placed-' . $table->id . '"', false);
    }

    public function test_adjacent_tables_keep_their_cells_with_no_shuffle(): void
    {
        $session = $this->openSession();
        $a = $this->table(name: '1');
        $b = $this->table(name: '2');
        // A at cell (5,2); B directly below at cell (5,3).
        $a->update(['pos_x' => 486, 'pos_y' => 198]);
        $b->update(['pos_x' => 486, 'pos_y' => 294]);

        // Both render (neither is lost or shuffled away) — distinct cells.
        Livewire::test(PosFloorPlan::class, ['session' => $session->id])
            ->call('selectFloor', $a->pos_floor_id)
            ->assertSee('wire:key="placed-' . $a->id . '"', false)
            ->assertSee('wire:key="placed-' . $b->id . '"', false);
    }

    public function test_canvas_is_a_fixed_size_regardless_of_table_position(): void
    {
        $session = $this->openSession();
        $t = $this->table(name: '3');
        // A position far outside the grid would have grown the old canvas; the
        // fixed canvas stays 12×5 cells and clamps the table inside. Cell size
        // is a responsive CSS var (smaller on phone/tablet), so the canvas is
        // sized in cell counts, not raw px.
        $t->update(['pos_x' => 5000, 'pos_y' => 5000]);

        Livewire::test(PosFloorPlan::class, ['session' => $session->id])
            ->call('selectFloor', $t->pos_floor_id)
            ->assertSee('width: calc(var(--cell) * 12); height: calc(var(--cell) * 5)');
    }

    public function test_double_click_unplaces_a_table_back_to_the_tray(): void
    {
        $session = $this->openSession();
        $table = $this->table(name: '9');
        $table->update(['pos_x' => 6, 'pos_y' => 6]);

        Livewire::test(PosFloorPlan::class, ['session' => $session->id])
            ->call('selectFloor', $table->pos_floor_id)
            ->call('unplaceTable', $table->id);

        $fresh = $table->fresh();
        $this->assertNull($fresh?->pos_x);
        $this->assertNull($fresh?->pos_y);
    }

    public function test_place_does_nothing_without_a_picked_up_table(): void
    {
        $session = $this->openSession();
        $table = $this->table(name: '6');

        Livewire::test(PosFloorPlan::class, ['session' => $session->id])
            ->call('selectFloor', $table->pos_floor_id)
            ->call('placeAt', 3, 3); // nothing selected → no-op

        $this->assertNull($table->fresh()?->pos_x);
    }

    public function test_select_table_is_write_gated_for_non_managers(): void
    {
        $session = $this->openSession();
        $table = $this->table(name: '7');

        ModelAccess::query()->create(['name' => 'order-read', 'model' => 'pos.order', 'perm_read' => true]);
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        Livewire::test(PosFloorPlan::class, ['session' => $session->id])
            ->call('selectFloor', $table->pos_floor_id)
            ->call('selectTable', $table->id)
            ->assertForbidden();
    }

    public function test_move_table_clamps_coordinates(): void
    {
        $session = $this->openSession();
        $table = $this->table(name: '6');

        Livewire::test(PosFloorPlan::class, ['session' => $session->id])
            ->call('selectFloor', $table->pos_floor_id)
            ->call('moveTable', $table->id, 999999, -5);

        $fresh = $table->fresh();
        $this->assertSame(8000, $fresh?->pos_x); // capped at MAX_POS
        $this->assertSame(0, $fresh?->pos_y); // never negative
    }

    public function test_move_table_is_write_gated_for_non_managers(): void
    {
        $session = $this->openSession();
        $table = $this->table(name: '7');

        // A cashier-style user: may Read pos.order (so the plan opens) but has
        // no pos.table Write — arranging must be forbidden.
        ModelAccess::query()->create(['name' => 'order-read', 'model' => 'pos.order', 'perm_read' => true]);
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        Livewire::test(PosFloorPlan::class, ['session' => $session->id])
            ->call('selectFloor', $table->pos_floor_id)
            ->assertSet('canEdit', false)
            ->call('moveTable', $table->id, 1, 1)
            ->assertForbidden();

        $this->assertNull($table->fresh()?->pos_x);
    }

    public function test_toggle_line_adds_then_removes_a_divider(): void
    {
        $session = $this->openSession();
        $table = $this->table(name: '8');
        $floorId = $table->pos_floor_id;

        $component = Livewire::test(PosFloorPlan::class, ['session' => $session->id])
            ->call('selectFloor', $floorId)
            ->call('toggleLine', 'v', 3);

        $this->assertDatabaseHas('pos_floor_lines', [
            'pos_floor_id' => $floorId,
            'orientation' => 'v',
            'position' => 3,
        ]);

        // Clicking the same gutter again removes it.
        $component->call('toggleLine', 'v', 3);
        $this->assertSame(0, PosFloorLine::query()->where('pos_floor_id', $floorId)->count());
    }

    public function test_toggle_line_ignores_a_bad_orientation_or_position(): void
    {
        $session = $this->openSession();
        $table = $this->table(name: '9');

        Livewire::test(PosFloorPlan::class, ['session' => $session->id])
            ->call('selectFloor', $table->pos_floor_id)
            ->call('toggleLine', 'diagonal', 2)
            ->call('toggleLine', 'h', 0);

        $this->assertSame(0, PosFloorLine::query()->count());
    }

    public function test_toggle_line_is_write_gated_for_non_managers(): void
    {
        $session = $this->openSession();
        $table = $this->table(name: '10');

        ModelAccess::query()->create(['name' => 'order-read', 'model' => 'pos.order', 'perm_read' => true]);
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        Livewire::test(PosFloorPlan::class, ['session' => $session->id])
            ->call('selectFloor', $table->pos_floor_id)
            ->call('toggleLine', 'v', 2)
            ->assertForbidden();

        $this->assertSame(0, PosFloorLine::query()->count());
    }

    public function test_floor_name_is_translatable_per_locale(): void
    {
        $floor = PosFloor::query()->create(['name' => 'Patio', 'sequence' => 10, 'active' => true]);
        $floor->setTranslation('name', 'ar', 'الفناء');
        $floor->save();

        $fresh = PosFloor::query()->findOrFail($floor->id);
        $this->assertSame('Patio', $fresh->getTranslation('name', 'en'));
        $this->assertSame('الفناء', $fresh->getTranslation('name', 'ar'));

        // Active-locale read follows app()->getLocale().
        app()->setLocale('ar');
        $this->assertSame('الفناء', PosFloor::query()->findOrFail($floor->id)->name);
        app()->setLocale('en');

        // The form arch marks the name field translatable (EN/AR pills).
        $form = collect(PosFloor::irModelDefinition()->views)
            ->first(fn ($v): bool => $v->type === 'form');
        $nameField = collect($form->arch['fields'] ?? [])->firstWhere('field', 'name');
        $this->assertTrue((bool) ($nameField['translatable'] ?? false));
    }
}
