<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The contact details printed in the footer band on every customer document.
 *
 * `company.phone` and `company.email` were already READ by the limousine PDF
 * services, but no `ir_config_parameter` row ever existed for them — so they
 * were unreachable from Settings and every footer printed the company name
 * alone. These rows put them (and the address the band needs) on the General
 * tab where an admin can fill them in.
 *
 * A data migration rather than a seeder edit alone, because `SettingSeeder`
 * only runs against Main on deploy — this reaches every existing workspace
 * through `workspaces:migrate`. Insert-only: an admin's saved value is never
 * touched, and re-running changes nothing.
 */
return new class extends Migration
{
    /** @var list<array{key: string, label: string, sort: int, description: string}> */
    private array $params = [
        ['key' => 'company.phone', 'label' => 'Phone (hotline)', 'sort' => 21, 'description' => 'The main number, printed first in the footer of every document.'],
        ['key' => 'company.phone_alt', 'label' => 'Other phone numbers', 'sort' => 22, 'description' => 'Any further numbers, separated by commas. Printed after the hotline.'],
        ['key' => 'company.address', 'label' => 'Address', 'sort' => 23, 'description' => 'Printed on the right of the footer of every document.'],
        ['key' => 'company.email', 'label' => 'Email', 'sort' => 24, 'description' => 'Printed in the footer of every document.'],
        ['key' => 'company.website', 'label' => 'Website', 'sort' => 25, 'description' => 'Printed in the footer of every document.'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('ir_config_parameter')) {
            return;
        }

        foreach ($this->params as $p) {
            if (DB::table('ir_config_parameter')->where('key', $p['key'])->exists()) {
                continue;
            }

            DB::table('ir_config_parameter')->insert([
                'key' => $p['key'],
                'value' => '',
                'type' => 'string',
                'group' => 'General',
                'label' => $p['label'],
                'description' => $p['description'],
                'sort' => $p['sort'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ir_config_parameter')) {
            return;
        }

        DB::table('ir_config_parameter')
            ->whereIn('key', array_column($this->params, 'key'))
            ->delete();
    }
};
