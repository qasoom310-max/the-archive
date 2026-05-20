<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Auth\Group;
use App\Models\Auth\ModelAccess;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds security groups, an admin (superuser) + a non-admin demo user,
 * and the default ir_model_access rules.
 *
 *   admin@example.com / password  — superuser (bypasses all ACLs)
 *   sales@example.com / password  — Contacts user: read/write/create
 *                                   partners but NOT delete; demo tickets
 *                                   read-only.
 */
final class AuthSeeder extends Seeder
{
    public function run(): void
    {
        $adminGroup = Group::query()->updateOrCreate(
            ['code' => 'admin'],
            ['name' => 'Administration / Settings', 'description' => 'Full system access'],
        );

        $userGroup = Group::query()->updateOrCreate(
            ['code' => 'contacts_user'],
            ['name' => 'Contacts / User', 'description' => 'Day-to-day contacts access'],
        );

        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Administrator', 'is_admin' => true, 'password' => Hash::make('password')],
        );
        $admin->groups()->syncWithoutDetaching([$adminGroup->id]);

        // Additional human admins — full superuser access, same level as
        // admin@example.com. Email is real (not null) so the make-email-
        // nullable migration's down() can roll back cleanly under
        // DatabaseMigrations in tests. They can still sign in by username
        // because the login form accepts email OR name.
        /** @var list<array{name: string, email: string, password: string}> $extraAdmins */
        $extraAdmins = [
            ['name' => 'via',    'email' => 'via@example.com',    'password' => 'V1234567v'],
            ['name' => 'hassan', 'email' => 'hassan@example.com', 'password' => 'H1234567h'],
        ];
        foreach ($extraAdmins as $a) {
            $u = User::query()->updateOrCreate(
                ['name' => $a['name']],
                [
                    'email' => $a['email'],
                    'is_admin' => true,
                    'password' => Hash::make($a['password']),
                ],
            );
            $u->groups()->syncWithoutDetaching([$adminGroup->id]);
        }

        $user = User::query()->updateOrCreate(
            ['email' => 'sales@example.com'],
            ['name' => 'Sales User', 'is_admin' => false, 'password' => Hash::make('password')],
        );
        $user->groups()->syncWithoutDetaching([$userGroup->id]);

        $rules = [
            ['Partner: user', 'contacts.partner', $userGroup->id, true, true, true, false],
            ['Demo ticket: user (read only)', 'demo.ticket', $userGroup->id, true, false, false, false],
        ];

        foreach ($rules as [$name, $model, $groupId, $read, $write, $create, $unlink]) {
            ModelAccess::query()->updateOrCreate(
                ['model' => $model, 'group_id' => $groupId],
                [
                    'name' => $name,
                    'perm_read' => $read,
                    'perm_write' => $write,
                    'perm_create' => $create,
                    'perm_unlink' => $unlink,
                ],
            );
        }
    }
}
