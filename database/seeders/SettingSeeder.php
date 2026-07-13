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
            // Business type drives which apps, menus and features appear for
            // THIS database (each workspace picks its own). Empty default =
            // "not configured" → everything stays visible (see Features).
            ['key' => 'company.business_type', 'label' => 'Business Type', 'type' => 'string', 'group' => 'General', 'default' => '', 'sort' => 5, 'description' => 'Tailors which apps, menus, and features appear for this database (e.g. a perfume shop hides café recipes).'],
            ['key' => 'company.name', 'label' => 'Company Name', 'type' => 'string', 'group' => 'General', 'default' => 'OpenERP', 'sort' => 10, 'description' => 'Shown on receipts and documents.'],
            // `image` is a UI-only flag — the column stores the path on
            // the public disk (e.g. `company/abc.webp`). Empty default;
            // admins upload via the Settings UI, the file lands in
            // `storage/app/public/company/`. Render through
            // `App\Erp\Branding\Logo::url()` so a missing-file path
            // falls back to text branding (same guard as User::avatarUrl).
            ['key' => 'company.logo', 'label' => 'Company Logo', 'type' => 'image', 'group' => 'General', 'default' => '', 'sort' => 15, 'description' => 'Shown on receipts, the login page, and the topbar.'],
            // How big the logo prints on documents (the agreement PDF): a percent
            // of the default size. 100 = default, larger zooms in (e.g. 150).
            ['key' => 'company.logo_scale', 'label' => 'Logo size on documents (%)', 'type' => 'string', 'group' => 'General', 'default' => '100', 'sort' => 16, 'description' => '100 = default. Enter a larger number to zoom the logo in (e.g. 150 or 200).'],
            // Super-admin-only (the Settings page hides it from regular admins
            // via SettingsPage::SUPER_ADMIN_KEYS). Descriptive for now; can
            // later drive module suggestions / defaults.
            ['key' => 'currency.default', 'label' => 'Default Currency', 'type' => 'string', 'group' => 'General', 'default' => 'USD', 'sort' => 20, 'description' => 'ISO code, e.g. USD, EUR.'],
            ['key' => 'company.timezone', 'label' => 'Timezone', 'type' => 'string', 'group' => 'General', 'default' => 'UTC', 'sort' => 30, 'description' => null],
            ['key' => 'company.language', 'label' => 'Language', 'type' => 'string', 'group' => 'General', 'default' => 'en', 'sort' => 40, 'description' => 'Default language for the system.'],
            // Terms & conditions printed on the rental Car Hire Agreement PDF
            // (the one emailed to customers). A `text` type renders as a textarea.
            ['key' => 'rental.agreement_terms', 'label' => 'Car hire agreement terms', 'type' => 'text', 'group' => 'Rental', 'default' => '', 'sort' => 10, 'description' => 'Terms & conditions printed on the rental agreement PDF emailed to customers.'],
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
