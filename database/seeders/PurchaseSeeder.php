<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Auth\Group;
use App\Models\Auth\ModelAccess;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Modules\Contacts\Models\Partner;

/**
 * The "Purchases / User" group + ACLs, plus a demo vendor so the vendor
 * picker isn't empty. No-ops until the module's tables exist; the group +
 * ACL rules are re-asserted on every run (idempotent). Deliberately does NOT
 * create a confirmed bill — that would mutate POS/Inventory stock as a side
 * effect of seeding.
 *
 * Lives at the project-root `database/seeders/` under `Database\Seeders`
 * (PSR-4 maps only that path — see memory `module-seeders-live-at-project-root`).
 */
final class PurchaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('purchases')) {
            return;
        }

        $group = Group::query()->updateOrCreate(
            ['code' => 'purchase_user'],
            ['name' => 'Purchases / User', 'description' => 'Record and confirm vendor bills'],
        );

        $sales = User::query()->where('email', 'sales@example.com')->first();
        if ($sales !== null) {
            $sales->groups()->syncWithoutDetaching([$group->id]);
        }

        // Full operate (read/write/create) but no delete.
        ModelAccess::query()->updateOrCreate(
            ['model' => 'purchases.purchase', 'group_id' => $group->id],
            [
                'name' => 'purchases.purchase: Purchases user',
                'perm_read' => true,
                'perm_write' => true,
                'perm_create' => true,
                'perm_unlink' => false,
            ],
        );

        if (Schema::hasTable('partners') && ! Partner::query()->where('name', 'Gulf Coal & Supplies')->exists()) {
            Partner::query()->create([
                'name' => 'Gulf Coal & Supplies',
                'is_company' => true,
            ]);
        }
    }
}
