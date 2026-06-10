<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Livewire\PosFloorPlan;
use Modules\Pos\Livewire\PosHome;
use Modules\Pos\Livewire\PosTerminal;
use Modules\Pos\Models\PosFloor;
use Modules\Pos\Models\PosOrder;
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

    private function table(int $seats = 4, string $name = '1'): PosTable
    {
        $floor = PosFloor::query()->firstOrCreate(['name' => 'Main floor'], ['sequence' => 10]);

        return PosTable::query()->create([
            'pos_floor_id' => $floor->id,
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

    public function test_set_guests_clamps_between_zero_and_capacity(): void
    {
        $session = $this->openSession();
        $table = $this->table(seats: 4);

        $component = Livewire::test(PosTerminal::class, ['session' => $session->id, 'table' => $table->id])
            ->call('setGuests', 10);

        $order = PosOrder::query()->where('pos_table_id', $table->id)->firstOrFail();
        $this->assertSame(4, (int) $order->guest_count); // clamped to seats

        $component->call('setGuests', -100);
        $this->assertSame(0, (int) $order->fresh()?->guest_count); // never negative
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
        $product = PosProduct::query()->create(['name' => 'Latte', 'price' => 3.0, 'tax_rate' => 0.0, 'active' => true]);

        // Empty floor first: the table shows and reads "0/4".
        Livewire::test(PosFloorPlan::class, ['session' => $session->id])
            ->assertSee('7')
            ->assertSee('0/4');

        // Seat 2 guests + add a product → the table is now occupied.
        Livewire::test(PosTerminal::class, ['session' => $session->id, 'table' => $table->id])
            ->call('setGuests', 2)
            ->call('addProduct', $product->id);

        Livewire::test(PosFloorPlan::class, ['session' => $session->id])
            ->assertSee('2/4');
    }
}
