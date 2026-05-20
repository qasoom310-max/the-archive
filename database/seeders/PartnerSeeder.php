<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Modules\Contacts\Models\Partner;

/**
 * Demo contacts. No-ops until the Contacts module is installed (the
 * `partners` table is created by `php artisan module:install contacts`),
 * so it is safe to keep in the default seeder chain.
 */
final class PartnerSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('partners') || Partner::query()->exists()) {
            return;
        }

        $partners = [
            ['name' => 'Acme Corporation', 'is_company' => true, 'email' => 'hello@acme.test', 'phone' => '+1 415 555 0100', 'city' => 'San Francisco', 'country' => 'United States'],
            ['name' => 'Jane Cooper', 'is_company' => false, 'email' => 'jane.cooper@acme.test', 'phone' => '+1 415 555 0142', 'city' => 'San Francisco', 'country' => 'United States'],
            ['name' => 'Globex SARL', 'is_company' => true, 'email' => 'contact@globex.test', 'phone' => '+33 1 70 00 00 00', 'city' => 'Paris', 'country' => 'France'],
            ['name' => 'Marc Dubois', 'is_company' => false, 'email' => 'marc.dubois@globex.test', 'phone' => '+33 6 12 34 56 78', 'city' => 'Lyon', 'country' => 'France'],
            ['name' => 'Initech GmbH', 'is_company' => true, 'email' => 'info@initech.test', 'phone' => '+49 30 123456', 'city' => 'Berlin', 'country' => 'Germany'],
            ['name' => 'Sarah Müller', 'is_company' => false, 'email' => 'sarah.mueller@initech.test', 'phone' => '+49 170 1234567', 'city' => 'Munich', 'country' => 'Germany'],
        ];

        foreach ($partners as $data) {
            $partner = Partner::query()->create($data);
            $partner->logChange('Record created.');
        }
    }
}
