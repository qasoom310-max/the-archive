<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Erp\Enums\ModuleState;
use App\Models\Ir\IrModule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds a few placeholder *application* modules so the app-switcher,
 * sidebar and command palette are populated before real modules are
 * installed. These are demo rows (no manifest on disk) — the Contacts
 * module in Phase 5 is installed for real via the ModuleManager.
 */
final class DemoAppSeeder extends Seeder
{
    public function run(): void
    {
        $apps = [
            ['name' => 'crm', 'display_name' => 'CRM', 'summary' => 'Track leads & opportunities', 'sequence' => 10],
            ['name' => 'sales', 'display_name' => 'Sales', 'summary' => 'Quotations & sales orders', 'sequence' => 20],
            ['name' => 'inventory', 'display_name' => 'Inventory', 'summary' => 'Stock & warehouses', 'sequence' => 30],
            ['name' => 'project', 'display_name' => 'Project', 'summary' => 'Tasks & timesheets', 'sequence' => 40],
            ['name' => 'settings', 'display_name' => 'Settings', 'summary' => 'Configure your system', 'sequence' => 90],
        ];

        foreach ($apps as $app) {
            IrModule::query()->updateOrCreate(
                ['name' => $app['name']],
                [
                    ...$app,
                    'version' => '1.0.0',
                    'application' => true,
                    'state' => ModuleState::Installed,
                    'installed_version' => '1.0.0',
                    'installed_at' => Carbon::now(),
                ],
            );
        }
    }
}
