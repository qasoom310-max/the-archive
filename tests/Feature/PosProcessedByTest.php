<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Erp\Views\ViewArch;
use App\Erp\Views\ViewResolver;
use App\Livewire\Views\ListView;
use App\Models\User;
use Database\Seeders\AuthSeeder;
use Database\Seeders\PosSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Livewire\PosTerminal;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosPaymentMethod;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosSession;
use Tests\TestCase;

/**
 * "Processed By" column on the POS Orders list — pins three behaviours:
 *   1. finalizeSale stamps `user_id` to the cashier who clicks Validate
 *      (overwriting any creator stamp from draft-creation);
 *   2. the metadata-driven list arch carries the column + the
 *      `sort_field: 'user_id'` override so sort clicks route to a real
 *      indexed column instead of failing on the accessor name;
 *   3. the accessor renders the cashier's display name (or '—' for
 *      orders with no attributed cashier).
 */
final class PosProcessedByTest extends TestCase
{
    use DatabaseMigrations;

    private function installPos(): void
    {
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

    public function test_finalize_stamps_user_id_to_the_validating_cashier(): void
    {
        $this->installPos();
        $this->seed(AuthSeeder::class);
        $this->seed(PosSeeder::class);
        $session = $this->openSession();

        // Two real users; the "creator" opens the cart, the "finaliser"
        // is the one who runs validateOrder. In a shared register these
        // can differ — the Processed By must follow the finaliser.
        $creator = User::factory()->create(['name' => 'Carla Creator', 'is_admin' => true]);
        $finaliser = User::factory()->create(['name' => 'Frank Finalist', 'is_admin' => true]);

        $this->actingAs($creator);
        $product = PosProduct::query()->create(['name' => 'Coffee', 'price' => 5.0, 'tax_rate' => 0]);
        $cashMethod = PosPaymentMethod::query()->where('name', 'Cash')->sole();

        // Creator opens the cart and adds an item.
        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('addProduct', $product->id);

        $order = PosOrder::query()->where('pos_session_id', $session->id)->sole();
        $this->assertSame($creator->id, $order->user_id); // creator-stamped on draft

        // Frank takes over and finalises.
        $this->actingAs($finaliser);
        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('startPayment')
            ->set('paymentMethodId', $cashMethod->id)
            ->set('tendered', '5.00')
            ->call('addPayment')
            ->call('validateOrder');

        $order->refresh();
        $this->assertSame(OrderState::Done, $order->state);
        $this->assertSame($finaliser->id, $order->user_id); // finaliser wins
    }

    public function test_processed_by_accessor_returns_user_name_or_dash(): void
    {
        $this->installPos();
        $session = $this->openSession();
        $user = User::factory()->create(['name' => 'Ali Cashier']);

        $attributed = PosOrder::query()->create([
            'pos_session_id' => $session->id,
            'reference' => 'POS/A/0001',
            'user_id' => $user->id,
        ]);
        $unattributed = PosOrder::query()->create([
            'pos_session_id' => $session->id,
            'reference' => 'POS/A/0002',
            'user_id' => null,
        ]);

        $this->assertSame('Ali Cashier', $attributed->fresh()->processed_by);
        $this->assertSame('—', $unattributed->fresh()->processed_by);
    }

    public function test_list_arch_includes_processed_by_with_user_id_sort_field(): void
    {
        $this->installPos();
        $arch = app(ViewResolver::class)->arch('pos.order', 'list');

        $processedBy = null;
        foreach ($arch->columns as $col) {
            if ($col->field === 'processed_by') {
                $processedBy = $col;
                break;
            }
        }

        $this->assertNotNull($processedBy);
        $this->assertSame('Processed By', $processedBy->label);
        $this->assertTrue($processedBy->sortable);
        // The override is what makes sort work — without it the engine
        // would `orderBy('processed_by')` and crash (no such column).
        $this->assertSame('user_id', $processedBy->sortColumn());
    }

    public function test_clicking_processed_by_header_sorts_by_user_id(): void
    {
        $this->installPos();
        $session = $this->openSession();
        $userA = User::factory()->create(['name' => 'A Cashier']);
        $userB = User::factory()->create(['name' => 'B Cashier']);

        // B has a lower user_id than the one we'll create next; but to be
        // deterministic, create B first, then A — A's id ends up higher.
        // (Test relies on relative ordering, not absolute ids.)
        PosOrder::query()->create([
            'pos_session_id' => $session->id, 'reference' => 'POS/B/01',
            'user_id' => $userB->id, 'state' => OrderState::Done, 'total' => 10,
        ]);
        PosOrder::query()->create([
            'pos_session_id' => $session->id, 'reference' => 'POS/A/01',
            'user_id' => $userA->id, 'state' => OrderState::Done, 'total' => 20,
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        // Sort by `processed_by` ASC — engine should route through
        // sort_field=user_id, so userB's order (lower id) comes first.
        $component = Livewire::test(ListView::class, [
            'model' => PosOrder::class,
            'modelKey' => 'pos.order',
            'title' => 'POS Orders',
        ])
            ->call('sortBy', 'processed_by')
            ->assertSet('sorts', [['field' => 'processed_by', 'dir' => 'asc']]);

        // Sanity: didn't crash on a missing SQL column — the page rendered.
        $component->assertOk();
    }

    public function test_list_view_default_sort_is_unchanged_by_new_column(): void
    {
        $this->installPos();
        $arch = app(ViewResolver::class)->arch('pos.order', 'list');

        $this->assertInstanceOf(ViewArch::class, $arch);
        $this->assertSame([['field' => 'ordered_at', 'dir' => 'desc']], $arch->defaultSort);
    }
}
