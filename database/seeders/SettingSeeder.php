<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Ir\IrConfigParameter;
use Illuminate\Database\Seeder;

/**
 * Baseline system settings. Idempotent and **non-destructive**: metadata
 * (label/type/group/sort) is refreshed, but an existing `value` is left
 * untouched so re-seeding never clobbers an admin's saved configuration.
 *
 * Only the General group is scaffolded here; POS / Inventory groups are
 * future increments (just more rows — the UI is fully data-driven).
 */
final class SettingSeeder extends Seeder
{
    public function run(): void
    {
        /** @var list<array{key: string, label: string, type: string, group: string, default: string, sort: int, description: string|null}> $params */
        $params = [
            ['key' => 'company.name', 'label' => 'Company Name', 'type' => 'string', 'group' => 'General', 'default' => 'OpenERP', 'sort' => 10, 'description' => 'Shown on receipts and documents.'],
            ['key' => 'currency.default', 'label' => 'Default Currency', 'type' => 'string', 'group' => 'General', 'default' => 'USD', 'sort' => 20, 'description' => 'ISO code, e.g. USD, EUR.'],
            ['key' => 'company.timezone', 'label' => 'Timezone', 'type' => 'string', 'group' => 'General', 'default' => 'UTC', 'sort' => 30, 'description' => null],
            ['key' => 'company.language', 'label' => 'Language', 'type' => 'string', 'group' => 'General', 'default' => 'en', 'sort' => 40, 'description' => 'Default language for the system.'],
        ];

        foreach ($params as $p) {
            $param = IrConfigParameter::query()->firstOrNew(['key' => $p['key']]);

            $param->type = $p['type'];
            $param->group = $p['group'];
            $param->label = $p['label'];
            $param->description = $p['description'];
            $param->sort = $p['sort'];

            if (! $param->exists) {
                $param->value = $p['default'];
            }

            $param->save();
        }
    }
}
