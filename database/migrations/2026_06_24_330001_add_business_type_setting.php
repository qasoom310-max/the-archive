<?php

declare(strict_types=1);

use App\Models\Ir\IrConfigParameter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Seed the per-database "Business Type" setting so the new Business
 * configuration tab appears on every existing install. SettingSeeder already
 * adds it for freshly-provisioned workspaces; this backfills Main (via
 * `migrate --force`) and every existing tenant (via `workspaces:migrate`,
 * which runs core migrations against each SQLite file).
 *
 * Idempotent and non-destructive: an already-present row keeps its saved
 * value; only the metadata is refreshed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ir_config_parameter')) {
            return;
        }

        $param = IrConfigParameter::query()->firstOrNew(['key' => 'company.business_type']);

        $param->type = 'string';
        $param->group = 'Business';
        $param->label = 'Business Type';
        $param->description = 'Tailors which apps, menus, and features appear for this database (e.g. a perfume shop hides café recipes).';
        $param->sort = 10;

        // Empty value = "not configured" → Features treats every capability
        // as enabled, so existing businesses lose nothing until they choose.
        if (! $param->exists) {
            $param->value = '';
        }

        $param->save();
    }

    public function down(): void
    {
        if (! Schema::hasTable('ir_config_parameter')) {
            return;
        }

        IrConfigParameter::query()->where('key', 'company.business_type')->delete();
    }
};
