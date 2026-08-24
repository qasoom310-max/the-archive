<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Livewire\Views\ListView;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Pos\Models\PosCategory;
use Modules\Pos\Models\PosIngredient;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosProductRecipe;
use Tests\TestCase;

/**
 * A list column backed by an accessor reads through a relation, and Eloquent
 * lazy-loads that per row. The product list has two ("Category" and "Available
 * servings"), so a 20-row page ran around a hundred queries — repeated on every
 * sort click and every keystroke in the search box.
 *
 * Arch declares what to eager-load; the engine applies it to the page query.
 */
final class ListViewEagerLoadTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('pos');
    }

    public function test_the_product_list_does_not_query_once_per_row(): void
    {
        $category = PosCategory::query()->create(['name' => 'Perfume', 'active' => true, 'sequence' => 1]);
        $oil = PosIngredient::query()->create(['name' => 'Oil', 'unit' => 'ml', 'stock_on_hand' => 500, 'cost_price' => 1]);

        for ($i = 1; $i <= 20; $i++) {
            $product = PosProduct::query()->create([
                'name' => "Product {$i}", 'price' => 10, 'tax_rate' => 0,
                'active' => true, 'pos_category_id' => $category->id,
            ]);
            PosProductRecipe::query()->create([
                'parent_product_id' => $product->id,
                'component_ingredient_id' => $oil->id,
                'quantity_consumed' => 1,
            ]);
        }

        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        Livewire::test(ListView::class, [
            'model' => PosProduct::class,
            'modelKey' => 'pos.product',
        ])->assertOk();

        // Before eager-loading this was well over a hundred and grew with the
        // page size; it is now a fixed handful regardless of how many rows show.
        $this->assertLessThan(40, $queries, "the product list used {$queries} queries for 20 rows");
    }
}
