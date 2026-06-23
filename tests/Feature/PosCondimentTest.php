<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Livewire\PosProductCondiments;
use Modules\Pos\Livewire\PosTerminal;
use Modules\Pos\Models\PosCategory;
use Modules\Pos\Models\PosCondiment;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosOrderLine;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosSession;
use Tests\TestCase;

/**
 * Condiments / add-ons: a global priced list the cashier toggles onto any
 * cart line at the register. A condiment is a per-unit surcharge (0 = free)
 * and travels with the line to the kitchen + receipt.
 */
final class PosCondimentTest extends TestCase
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

    private function product(float $price = 5.0, float $tax = 0.0): PosProduct
    {
        return PosProduct::query()->create([
            'name' => 'Burger',
            'price' => $price,
            'tax_rate' => $tax,
            'active' => true,
        ]);
    }

    public function test_condiment_surcharge_is_per_unit_and_multiplies_by_quantity(): void
    {
        $line = new PosOrderLine([
            'name' => 'Burger',
            'qty' => 2.0,
            'unit_price' => 5.0,
            'discount' => 0.0,
            'tax_rate' => 0.0,
            'condiments' => [
                ['id' => 1, 'name' => 'Extra cheese', 'price' => 0.5],
                ['id' => 2, 'name' => 'No ice', 'price' => 0.0],
            ],
        ]);

        $line->recompute();

        $this->assertSame(0.5, $line->condimentsSurcharge());
        // (5.0 + 0.5 surcharge) × 2 = 11.0
        $this->assertSame(11.0, $line->total);
    }

    public function test_toggle_condiment_updates_line_and_order_total_live(): void
    {
        $session = $this->openSession();
        $product = $this->product(price: 5.0);
        $cheese = PosCondiment::query()->create(['name' => 'Extra cheese', 'price' => 0.5, 'active' => true]);

        $component = Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('addProduct', $product->id);

        $order = PosOrder::query()->where('pos_session_id', $session->id)->firstOrFail();
        $line = $order->lines()->firstOrFail();
        $this->assertSame(5.0, (float) $line->total);

        $component->call('openCondiments', $line->id)
            ->assertSet('pickingCondiments', true)
            ->call('toggleCondiment', $cheese->id);

        $line->refresh();
        $order->refresh();
        $this->assertSame(5.5, (float) $line->total);
        $this->assertSame(5.5, (float) $order->total);
        $this->assertCount(1, (array) $line->condiments);

        // Tapping the same condiment again toggles it off.
        $component->call('toggleCondiment', $cheese->id);
        $line->refresh();
        $this->assertSame(5.0, (float) $line->total);
        $this->assertEmpty((array) $line->condiments);
    }

    public function test_picker_shows_only_the_products_assigned_condiments(): void
    {
        $session = $this->openSession();

        $burgers = PosCategory::query()->create(['name' => 'Burgers']);

        $burger = PosProduct::query()->create([
            'name' => 'Burger', 'price' => 5.0, 'tax_rate' => 0.0,
            'active' => true, 'pos_category_id' => $burgers->id,
        ]);

        // Same-category and global add-ons exist, but the picker is now driven
        // EXCLUSIVELY by the product's own assignment — category/global no
        // longer auto-appear.
        $bacon = PosCondiment::query()->create(['name' => 'Bacon', 'price' => 0.5, 'pos_category_id' => $burgers->id]);
        PosCondiment::query()->create(['name' => 'Napkin', 'price' => 0.0]); // global

        // Assign ONLY bacon to the product.
        $burger->condiments()->attach($bacon->id);

        $component = Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('addProduct', $burger->id);

        $line = PosOrder::query()->where('pos_session_id', $session->id)
            ->firstOrFail()->lines()->firstOrFail();

        $component->call('openCondiments', $line->id)
            ->assertSee('Bacon')         // assigned to the product
            ->assertDontSee('Napkin');   // global no longer auto-shows
    }

    public function test_a_condiment_assigned_to_a_product_appears_in_the_register_picker(): void
    {
        $session = $this->openSession();

        $burgers = PosCategory::query()->create(['name' => 'Burgers']);
        $drinks = PosCategory::query()->create(['name' => 'Drinks']);

        $burger = PosProduct::query()->create([
            'name' => 'Burger', 'price' => 5.0, 'tax_rate' => 0.0,
            'active' => true, 'pos_category_id' => $burgers->id,
        ]);

        // Scoped to a DIFFERENT category — would NOT show for the burger by the
        // category rules. Per-product assignment is the only thing that surfaces it.
        $syrup = PosCondiment::query()->create(['name' => 'Vanilla syrup', 'price' => 0.3, 'pos_category_id' => $drinks->id]);

        $term = Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('addProduct', $burger->id);

        $line = PosOrder::query()->where('pos_session_id', $session->id)
            ->firstOrFail()->lines()->firstOrFail();

        // Not assigned yet → hidden in the picker.
        $term->call('openCondiments', $line->id)->assertDontSee('Vanilla syrup');

        // Assign it to the product via the product-page editor.
        Livewire::test(PosProductCondiments::class, ['productId' => $burger->id])
            ->call('toggle', $syrup->id);

        $this->assertTrue($burger->condiments()->where('pos_condiments.id', $syrup->id)->exists());

        // Now the register offers it for this product.
        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('openCondiments', $line->id)
            ->assertSee('Vanilla syrup');

        // Toggling again detaches it.
        Livewire::test(PosProductCondiments::class, ['productId' => $burger->id])
            ->call('toggle', $syrup->id);

        $this->assertFalse($burger->condiments()->where('pos_condiments.id', $syrup->id)->exists());
    }

    public function test_re_adding_the_product_does_not_merge_into_a_condimented_line(): void
    {
        $session = $this->openSession();
        $product = $this->product(price: 5.0);
        $cheese = PosCondiment::query()->create(['name' => 'Extra cheese', 'price' => 0.5, 'active' => true]);

        $component = Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('addProduct', $product->id);

        $order = PosOrder::query()->where('pos_session_id', $session->id)->firstOrFail();
        $line = $order->lines()->firstOrFail();

        $component->call('openCondiments', $line->id)->call('toggleCondiment', $cheese->id);

        // Same product again → a fresh plain line, not a bump of the cheese line.
        $component->call('addProduct', $product->id);

        $this->assertSame(2, $order->lines()->count());
    }
}
