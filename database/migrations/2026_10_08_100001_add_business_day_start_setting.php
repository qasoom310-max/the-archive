<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The hour a trading day starts (App\Erp\Settings\BusinessDay), per database.
 *
 * Sweileh Café trades past midnight and counts its day 8 AM to 8 AM, so its
 * database (recognised by company name, as HappyHour does) starts at 8; every
 * other database keeps midnight. Insert-only: a value somebody saved stays.
 * A core migration, because `workspaces:migrate` carries it into every
 * workspace while `SettingSeeder` reaches Main only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ir_config_parameter')
            || DB::table('ir_config_parameter')->where('key', 'company.day_starts_at')->exists()) {
            return;
        }

        DB::table('ir_config_parameter')->insert([
            'key' => 'company.day_starts_at',
            'value' => $this->isSweileh() ? '8' : '0',
            'type' => 'number',
            'group' => 'General',
            'label' => 'Business day starts at (hour)',
            'description' => '0 = midnight. 8 means each day runs 8 AM to 8 AM the next morning, so after-midnight sales count for the evening before.',
            'sort' => 18,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(\App\Erp\Settings\SettingManager::class)->flush();
        } catch (Throwable) {
            // A stale cache only delays the change until the next save.
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ir_config_parameter')) {
            DB::table('ir_config_parameter')->where('key', 'company.day_starts_at')->delete();
        }
    }

    private function isSweileh(): bool
    {
        $name = DB::table('ir_config_parameter')->where('key', 'company.name')->value('value');
        $norm = strtolower((string) preg_replace('/[^a-zA-Z]/', '', (string) $name));

        return str_contains($norm, 'sweileh') || str_contains($norm, 'swelieh');
    }
};
