<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Business\Feature;
use App\Erp\Business\Features;
use App\Erp\Modules\ModuleManager;
use App\Erp\Settings\Setting;
use App\Erp\Settings\SettingManager;
use App\Models\User;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Pos\Livewire\PosDamageForm;
use Modules\Pos\Livewire\PosDamages;
use Modules\Pos\Models\PosCondiment;
use Modules\Pos\Models\PosDamage;
use Modules\Pos\Models\PosIngredient;
use Modules\Pos\Models\PosProduct;
use Tests\TestCase;

/**
 * Damage / waste log: writing off stock for products, ingredients and
 * condiments. Each entry decrements the item's on-hand and (for cost-tracked
 * items) values the loss; deleting an entry restores the stock. Gated behind
 * the Inventory feature so it shows for Café / Retail / Crafting only.
 */
final class PosDamageTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true, 'name' => 'Qassim']));
        app(ModuleManager::class)->install('pos');
        app(SettingManager::class)->flush();
    }

    public function test_logging_product_damage_reduces_stock_and_values_the_loss(): void
    {
        $coffee = PosProduct::query()->create([
            'name' => 'Latte', 'price' => 2, 'cost_price' => 0.5, 'tax_rate' => 0, 'active' => true, 'stock_on_hand' => 10,
        ]);

        Livewire::test(PosDamageForm::class)
            ->set('itemKey', 'p:' . $coffee->id)
            ->set('quantity', '3')
            ->set('reason', 'spoiled')
            ->call('save')
            ->assertHasNoErrors();

        $damage = PosDamage::query()->firstOrFail();
        $this->assertSame('product', $damage->item_type);
        $this->assertSame(3.0, $damage->quantity);
        $this->assertSame(0.5, $damage->unit_cost);
        $this->assertSame(1.5, $damage->loss_value); // 3 × 0.5
        $this->assertSame('Qassim', $damage->recorded_by);
        $this->assertStringStartsWith('DMG/', (string) $damage->reference);

        // Stock dropped 10 → 7.
        $this->assertSame(7.0, (float) $coffee->fresh()?->stock_on_hand);
    }

    public function test_logging_ingredient_damage_reduces_ingredient_stock(): void
    {
        $beans = PosIngredient::query()->create(['name' => 'Beans', 'cost_price' => 12, 'stock_on_hand' => 5]);

        Livewire::test(PosDamageForm::class)
            ->set('itemKey', 'i:' . $beans->id)
            ->set('quantity', '2')
            ->set('reason', 'expired')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(3.0, (float) $beans->fresh()?->stock_on_hand); // 5 − 2
        $this->assertSame(24.0, (float) PosDamage::query()->value('loss_value')); // 2 × 12
    }

    public function test_condiment_damage_records_zero_loss_value(): void
    {
        $sugar = PosCondiment::query()->create(['name' => 'Sugar', 'price' => 0, 'stock_on_hand' => 8]);

        Livewire::test(PosDamageForm::class)
            ->set('itemKey', 'c:' . $sugar->id)
            ->set('quantity', '2')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(6.0, (float) $sugar->fresh()?->stock_on_hand);
        // Condiments carry no tracked cost → loss value is zero.
        $this->assertSame(0.0, (float) PosDamage::query()->value('loss_value'));
    }

    public function test_deleting_a_damage_entry_restores_the_stock(): void
    {
        $milk = PosIngredient::query()->create(['name' => 'Milk', 'cost_price' => 1, 'stock_on_hand' => 10]);

        Livewire::test(PosDamageForm::class)
            ->set('itemKey', 'i:' . $milk->id)
            ->set('quantity', '4')
            ->call('save');

        $this->assertSame(6.0, (float) $milk->fresh()?->stock_on_hand);

        $damage = PosDamage::query()->firstOrFail();

        Livewire::test(PosDamages::class)
            ->call('delete', $damage->id);

        $this->assertSame(0, PosDamage::query()->count());
        $this->assertSame(10.0, (float) $milk->fresh()?->stock_on_hand); // restored
    }

    public function test_report_totals_the_loss_within_the_date_range(): void
    {
        $p = PosProduct::query()->create(['name' => 'Bun', 'price' => 1, 'cost_price' => 0.4, 'tax_rate' => 0, 'active' => true, 'stock_on_hand' => 100]);
        PosDamage::query()->create(['damaged_on' => '2026-06-10', 'item_type' => 'product', 'pos_product_id' => $p->id, 'quantity' => 5, 'unit_cost' => 0.4, 'reason' => 'broken']);
        PosDamage::query()->create(['damaged_on' => '2026-06-20', 'item_type' => 'product', 'pos_product_id' => $p->id, 'quantity' => 3, 'unit_cost' => 0.4, 'reason' => 'broken']);
        // Outside the range below.
        PosDamage::query()->create(['damaged_on' => '2026-05-01', 'item_type' => 'product', 'pos_product_id' => $p->id, 'quantity' => 9, 'unit_cost' => 0.4, 'reason' => 'broken']);

        Livewire::test(PosDamages::class)
            ->set('from', '2026-06-01')
            ->set('to', '2026-06-30')
            ->assertSet('reason', '')
            ->assertViewHas('count', 2)
            ->assertViewHas('totalLoss', fn (float $v): bool => abs($v - 3.2) < 0.001); // (5+3) × 0.4
    }

    public function test_validation_requires_an_item_and_a_positive_quantity(): void
    {
        Livewire::test(PosDamageForm::class)
            ->set('itemKey', '')
            ->set('quantity', '0')
            ->call('save')
            ->assertHasErrors(['itemKey', 'quantity']);

        $this->assertSame(0, PosDamage::query()->count());
    }

    public function test_damage_log_is_gated_to_inventory_business_types(): void
    {
        $this->seed(SettingSeeder::class);
        app(SettingManager::class)->flush();

        // Café / Retail / Crafting carry Inventory → damage shown.
        Setting::set('company.business_type', 'cafe');
        $this->assertTrue(Features::enabled(Feature::Inventory));
        $this->assertTrue(Features::modelAllowed('pos.damage'));

        // A limousine database has no stock → damage hidden.
        app(SettingManager::class)->flush();
        Setting::set('company.business_type', 'limousine');
        $this->assertFalse(Features::modelAllowed('pos.damage'));
    }
}
