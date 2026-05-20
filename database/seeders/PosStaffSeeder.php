<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Auth\Group;
use App\Models\Auth\ModelAccess;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Real POS cashier accounts: username-only (no email), scoped to the
 * "POS / User" group so they can operate the Point of Sale and nothing
 * else. Idempotent — keyed by name. Run manually, not part of the
 * default seed chain (these are real people, not demo fixtures).
 */
final class PosStaffSeeder extends Seeder
{
    public function run(): void
    {
        $posGroup = Group::query()->updateOrCreate(
            ['code' => 'pos_user'],
            ['name' => 'POS / User', 'description' => 'Operate the Point of Sale'],
        );

        /** @var list<array{name: string, password: string}> $staff */
        $staff = [
            ['name' => 'ramadan', 'password' => 'R1234567r'],
            ['name' => 'faraj', 'password' => 'F1234567f'],
            ['name' => 'osama', 'password' => 'O1234567o'],
        ];

        foreach ($staff as $person) {
            $user = User::query()->updateOrCreate(
                ['name' => $person['name']],
                [
                    'email' => null,
                    'is_admin' => false,
                    'password' => Hash::make($person['password']),
                ],
            );

            $user->groups()->syncWithoutDetaching([$posGroup->id]);
        }

        // Cashier policy: operate the POS for ORDERS ONLY — sessions +
        // orders. No product-catalogue access at all (deny-by-default):
        // they cannot view, add, edit or delete products in the back
        // office. Selling still works (the terminal isn't ACL-gated on
        // products). Drop any stale pos.product grant from this group.
        ModelAccess::query()
            ->where('group_id', $posGroup->id)
            ->where('model', 'pos.product')
            ->delete();

        // [read, write, create, unlink]
        $rules = [
            'pos.session' => [true, true, true, false],
            'pos.order' => [true, true, true, false],
        ];

        foreach ($rules as $model => [$read, $write, $create, $unlink]) {
            ModelAccess::query()->updateOrCreate(
                ['model' => $model, 'group_id' => $posGroup->id],
                [
                    'name' => $model . ': POS cashier',
                    'perm_read' => $read,
                    'perm_write' => $write,
                    'perm_create' => $create,
                    'perm_unlink' => $unlink,
                ],
            );
        }
    }
}
