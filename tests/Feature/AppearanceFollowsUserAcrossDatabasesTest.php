<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Tenancy\WorkspaceManager;
use App\Livewire\Pages\SettingsPage;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Appearance (dark mode, accent) is a personal preference — it must follow the
 * person across every database.
 *
 * Regression: inside a workspace, `Auth::user()` is that database's MIRROR row
 * (matched by email). Saving the theme there stranded it in whichever database
 * happened to be active, so switching database — or coming back to it — read
 * the mirror's empty column and reverted to light mode. The preference now
 * persists on the canonical Main row.
 */
final class AppearanceFollowsUserAcrossDatabasesTest extends TestCase
{
    use DatabaseMigrations;

    public function test_dark_mode_chosen_inside_a_workspace_is_saved_on_the_main_row(): void
    {
        $manager = app(WorkspaceManager::class);
        $admin = User::factory()->create(['is_admin' => true, 'email' => 'owner@kaleem.test', 'theme' => 'light']);
        $workspace = $manager->provision('Kaleem', $admin, ['contacts']);

        // Act as the person INSIDE the workspace — i.e. authenticated as that
        // database's mirror row, exactly as SetActiveWorkspace leaves a request.
        $manager->withTenant((string) $workspace->databasePath(), function (): void {
            $mirror = User::query()->where('email', 'owner@kaleem.test')->firstOrFail();
            $this->actingAs($mirror);

            Livewire::test(SettingsPage::class)->call('setTheme', 'dark');
        });

        // The MAIN row carries the preference, so it survives a database switch.
        $main = User::on(Workspace::$landlordConnection)
            ->where('email', 'owner@kaleem.test')
            ->firstOrFail();

        $this->assertSame('dark', $main->theme);
    }

    public function test_the_accent_chosen_inside_a_workspace_is_saved_on_the_main_row(): void
    {
        $manager = app(WorkspaceManager::class);
        $admin = User::factory()->create(['is_admin' => true, 'email' => 'owner@kaleem.test']);
        $workspace = $manager->provision('Kaleem', $admin, ['contacts']);

        $manager->withTenant((string) $workspace->databasePath(), function (): void {
            $mirror = User::query()->where('email', 'owner@kaleem.test')->firstOrFail();
            $this->actingAs($mirror);

            Livewire::test(SettingsPage::class)->call('setAccent', 'sky');
        });

        $main = User::on(Workspace::$landlordConnection)
            ->where('email', 'owner@kaleem.test')
            ->firstOrFail();

        $this->assertSame('sky', $main->accent);
    }

    public function test_canonical_returns_the_same_row_when_already_on_main(): void
    {
        $user = User::factory()->create(['email' => 'solo@main.test', 'theme' => 'dark']);

        $this->assertSame(DB::getDefaultConnection(), Workspace::$landlordConnection);
        $this->assertSame($user->id, $user->canonical()?->id);
    }

    public function test_saving_on_main_still_works(): void
    {
        $user = User::factory()->create(['email' => 'solo@main.test', 'theme' => 'light']);
        $this->actingAs($user);

        Livewire::test(SettingsPage::class)->call('setTheme', 'dark');

        $this->assertSame('dark', $user->fresh()?->theme);
    }
}
