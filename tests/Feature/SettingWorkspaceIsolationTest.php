<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Settings\SettingManager;
use App\Erp\Tenancy\WorkspaceManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Settings (`ir_config_parameter`) must stay isolated per workspace/database
 * even with a warm cache. Regression for the bug where, after a deploy cleared
 * the cache, Main was read first and populated the shared settings cache, so
 * every tenant (e.g. "kaleem") then served Main's company name / logo.
 */
final class SettingWorkspaceIsolationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_each_database_keeps_its_own_settings_with_a_warm_cache(): void
    {
        $settings = app(SettingManager::class);
        $manager = app(WorkspaceManager::class);

        // Main database.
        $settings->set('company.name', 'Main Company');

        // A separate workspace (its own ir_config_parameter table).
        $admin = User::factory()->create(['is_admin' => true, 'email' => 'admin@iso.test']);
        $workspace = $manager->provision('Kaleem', $admin, ['contacts']);

        $manager->withTenant((string) $workspace->databasePath(), function () use ($settings): void {
            $settings->set('company.name', 'Kaleem Perfume');
            $this->assertSame('Kaleem Perfume', $settings->get('company.name'));
        });

        // Warm the Main cache (this is what a fresh post-deploy request does).
        $this->assertSame('Main Company', $settings->get('company.name'));

        // The tenant must STILL read its own value, not Main's cached one.
        $manager->withTenant((string) $workspace->databasePath(), function () use ($settings): void {
            $this->assertSame('Kaleem Perfume', $settings->get('company.name'));
        });

        // And Main is unaffected by the tenant read.
        $this->assertSame('Main Company', $settings->get('company.name'));
    }

    public function test_clearing_the_cache_does_not_leak_main_settings_into_a_tenant(): void
    {
        $settings = app(SettingManager::class);
        $manager = app(WorkspaceManager::class);

        $settings->set('company.name', 'HQ');
        $admin = User::factory()->create(['is_admin' => true, 'email' => 'admin2@iso.test']);
        $workspace = $manager->provision('Branch', $admin, ['contacts']);
        $manager->withTenant((string) $workspace->databasePath(), fn () => $settings->set('company.name', 'Branch Co'));

        // Simulate the deploy: wipe the whole cache, then read Main first.
        Cache::flush();
        $this->assertSame('HQ', $settings->get('company.name'));

        $manager->withTenant((string) $workspace->databasePath(), function () use ($settings): void {
            $this->assertSame('Branch Co', $settings->get('company.name'));
        });
    }
}
