<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AuthSeeder::class,
            SettingSeeder::class,
            MailActivityTypeSeeder::class,
            DemoAppSeeder::class,
            DemoViewSeeder::class,
            DemoTicketSeeder::class,
            PartnerSeeder::class,
            PosSeeder::class,
            InventorySeeder::class,
        ]);
    }
}
