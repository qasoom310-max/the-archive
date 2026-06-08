<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Auth\Group;
use App\Models\Auth\ModelAccess;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Modules\Pos\Models\PosCategory;
use Modules\Pos\Models\PosCondiment;
use Modules\Pos\Models\PosCustomerDiscount;
use Modules\Pos\Models\PosPaymentMethod;
use Modules\Pos\Models\PosProduct;

/**
 * Demo POS catalogue, payment methods and the "POS / User" group + ACLs.
 * No-ops until the POS module is installed (its tables don't exist yet),
 * so it is safe in the default seeder chain.
 */
final class PosSeeder extends Seeder
{
    public function run(): void
    {
        // Condiments seed independently of the products guard below so a demo
        // add-on list exists even on a DB that already has products.
        $this->seedCondiments();
        $this->seedCustomerDiscounts();

        if (! Schema::hasTable('pos_products') || PosProduct::query()->exists()) {
            return;
        }

        $cats = [];
        foreach (['Beverages' => 10, 'Food' => 20, 'Misc' => 30] as $name => $seq) {
            $cats[$name] = PosCategory::query()->create(['name' => $name, 'sequence' => $seq])->id;
        }

        $products = [
            ['Espresso', 2.50, 10.0, 'Beverages', '500001'],
            ['Cappuccino', 3.20, 10.0, 'Beverages', '500002'],
            ['Still Water 50cl', 1.20, 5.5, 'Beverages', '500003'],
            ['Croissant', 1.80, 5.5, 'Food', '500010'],
            ['Club Sandwich', 6.90, 10.0, 'Food', '500011'],
            ['Caesar Salad', 8.50, 10.0, 'Food', '500012'],
            ['Gift Card', 25.00, 0.0, 'Misc', '500020'],
            ['Branded Mug', 9.90, 20.0, 'Misc', '500021'],
        ];

        foreach ($products as [$name, $price, $tax, $cat, $barcode]) {
            PosProduct::query()->create([
                'name' => $name,
                'price' => $price,
                'tax_rate' => $tax,
                'pos_category_id' => $cats[$cat],
                'barcode' => $barcode,
                'active' => true,
            ]);
        }

        PosPaymentMethod::query()->create(['name' => 'Cash', 'is_cash' => true, 'sequence' => 10]);
        PosPaymentMethod::query()->create(['name' => 'Card', 'is_cash' => false, 'sequence' => 20]);

        $posGroup = Group::query()->updateOrCreate(
            ['code' => 'pos_user'],
            ['name' => 'POS / User', 'description' => 'Operate the Point of Sale'],
        );

        $sales = User::query()->where('email', 'sales@example.com')->first();
        if ($sales !== null) {
            $sales->groups()->syncWithoutDetaching([$posGroup->id]);
        }

        // Cashiers operate the POS for orders only — sessions + orders,
        // NO product-catalogue access. [read, write, create, unlink]
        $rules = [
            'pos.session' => [true, true, true, false],
            'pos.order' => [true, true, true, false],
        ];

        foreach ($rules as $model => [$read, $write, $create, $unlink]) {
            ModelAccess::query()->updateOrCreate(
                ['model' => $model, 'group_id' => $posGroup->id],
                [
                    'name' => $model . ': POS user',
                    'perm_read' => $read,
                    'perm_write' => $write,
                    'perm_create' => $create,
                    'perm_unlink' => $unlink,
                ],
            );
        }
    }

    /**
     * A small global condiment list (priced + free) so the register picker
     * isn't empty. Idempotent — skipped once any condiment exists. Seeded
     * independently of the products guard so it runs on an existing DB too.
     */
    private function seedCondiments(): void
    {
        if (! Schema::hasTable('pos_condiments') || PosCondiment::query()->exists()) {
            return;
        }

        $condiments = [
            ['Extra cheese', 0.50, 10],
            ['Extra sauce', 0.30, 20],
            ['Ice cubes', 0.00, 30],
            ['No ice', 0.00, 40],
            ['Extra shot', 0.40, 50],
        ];

        foreach ($condiments as [$name, $price, $sequence]) {
            PosCondiment::query()->create([
                'name' => $name,
                'price' => $price,
                'sequence' => $sequence,
                'active' => true,
            ]);
        }
    }

    /**
     * One demo per-phone customer discount so the admin sees the shape of
     * the feature. Idempotent — skipped once any discount exists. Seeded
     * independently of the products guard so it runs on an existing DB too.
     */
    private function seedCustomerDiscounts(): void
    {
        if (! Schema::hasTable('pos_customer_discounts') || PosCustomerDiscount::query()->exists()) {
            return;
        }

        PosCustomerDiscount::query()->create([
            'phone' => '+973 33000000',
            'discount_percent' => 10,
            'label' => 'VIP — demo customer',
            'active' => true,
        ]);
    }
}
