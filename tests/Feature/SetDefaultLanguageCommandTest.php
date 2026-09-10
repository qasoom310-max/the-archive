<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Settings\Setting;
use App\Erp\Settings\SettingManager;
use App\Erp\Tenancy\WorkspaceManager;
use App\Models\User;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

/**
 * `company.language` — the fallback locale a guest on the login page (and
 * any signed-in user with no personal preference) gets — has no in-app
 * control: the Settings page's "Language" row is deliberately a PERSONAL
 * preference and never touches this system-wide row (see
 * {@see \App\Livewire\Pages\SettingsPage}). This command is the only way
 * to change it.
 */
final class SetDefaultLanguageCommandTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        $dir = storage_path('app/workspaces');
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.sqlite') ?: [] as $file) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_it_sets_the_default_language_on_main(): void
    {
        (new SettingSeeder())->run();
        Setting::set('company.language', 'ar');

        $this->artisan('language:set-default', ['code' => 'en'])->assertSuccessful();

        app(SettingManager::class)->flush();
        $this->assertSame('en', Setting::get('company.language'));
    }

    public function test_it_rejects_an_unsupported_code(): void
    {
        $this->artisan('language:set-default', ['code' => 'fr'])->assertFailed();
    }

    public function test_it_sets_every_workspace_too(): void
    {
        $owner = User::factory()->create(['is_admin' => true, 'is_super_admin' => true]);
        $workspace = app(WorkspaceManager::class)->provision('Branch', $owner, ['pos']);

        app(WorkspaceManager::class)->withTenant(
            (string) $workspace->databasePath(),
            static function (): void {
                Setting::set('company.language', 'ar');
            },
        );

        $this->artisan('language:set-default', ['code' => 'en'])->assertSuccessful();

        $tenantValue = app(WorkspaceManager::class)->withTenant(
            (string) $workspace->databasePath(),
            static fn (): mixed => Setting::get('company.language'),
        );
        $this->assertSame('en', $tenantValue);
        $this->assertSame('en', Setting::get('company.language'));
    }

    public function test_databases_option_restricts_to_the_given_workspace(): void
    {
        $owner = User::factory()->create(['is_admin' => true, 'is_super_admin' => true]);
        Setting::set('company.language', 'ar');
        $workspace = app(WorkspaceManager::class)->provision('Branch Two', $owner, ['pos']);

        app(WorkspaceManager::class)->withTenant(
            (string) $workspace->databasePath(),
            static function (): void {
                Setting::set('company.language', 'ar');
            },
        );

        $this->artisan('language:set-default', [
            'code' => 'en',
            '--databases' => (string) $workspace->id,
        ])->assertSuccessful();

        // Left alone on Main.
        app(SettingManager::class)->flush();
        $this->assertSame('ar', Setting::get('company.language'));

        $tenantValue = app(WorkspaceManager::class)->withTenant(
            (string) $workspace->databasePath(),
            static fn (): mixed => Setting::get('company.language'),
        );
        $this->assertSame('en', $tenantValue);
    }
}
