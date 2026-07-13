<?php

declare(strict_types=1);

use App\Models\Ir\IrConfigParameter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Move the "Business Type" setting out of its own "Business" tab into the
 * General tab. Purely a metadata (group/sort) change — the saved value is
 * untouched — so a database keeps its chosen business type. Backfills Main
 * (via `migrate --force`) and every existing tenant (via `workspaces:migrate`).
 *
 * With no parameter left in the "Business" group, that tab disappears; the row
 * now sorts to the top of General (sort 5, before Company Name at 10).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ir_config_parameter')) {
            return;
        }

        $param = IrConfigParameter::query()->where('key', 'company.business_type')->first();
        if ($param === null) {
            return;
        }

        $param->group = 'General';
        $param->sort = 5;
        $param->save();
    }

    public function down(): void
    {
        if (! Schema::hasTable('ir_config_parameter')) {
            return;
        }

        $param = IrConfigParameter::query()->where('key', 'company.business_type')->first();
        if ($param === null) {
            return;
        }

        $param->group = 'Business';
        $param->sort = 10;
        $param->save();
    }
};
