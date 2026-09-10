<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The trading registrations printed in the footer band of every document.
 *
 * The owner's old pre-printed rental receipt carried a VAT number and a CR
 * number beneath the phone numbers, and a customer's accounts department needs
 * both off the paperwork — the VAT number to reclaim the tax, the CR number to
 * file the document against a real registered trader.
 *
 * Insert-only, and a core migration rather than a seeder edit alone, because
 * `SettingSeeder` only runs against Main on deploy while `workspaces:migrate`
 * carries a core migration into every workspace.
 */
return new class extends Migration
{
    /** @var list<array{key: string, label: string, sort: int, description: string}> */
    private array $params = [
        ['key' => 'company.vat_number', 'label' => 'VAT number', 'sort' => 26, 'description' => 'Printed in the footer of every document, so a customer can reclaim the tax.'],
        ['key' => 'company.cr_number', 'label' => 'CR number', 'sort' => 27, 'description' => 'Commercial registration number, printed in the footer of every document.'],
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
