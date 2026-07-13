<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Pages\SettingsPage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Per-user appearance (dark/light/system) preference: the Settings control
 * writes `users.theme` and applies live via a `theme-changed` browser event.
 */
final class ThemeSettingTest extends TestCase
{
    use DatabaseMigrations;

    public function test_setting_a_theme_persists_and_dispatches_the_live_event(): void
    {
        $user = User::factory()->create(['is_admin' => true, 'theme' => null]);
        $this->actingAs($user);

        Livewire::test(SettingsPage::class)
            ->call('setTheme', 'dark')
            ->assertSet('theme', 'dark')
            ->assertDispatched('theme-changed', value: 'dark');

        $this->assertSame('dark', $user->fresh()?->theme);
    }

    public function test_mount_reflects_the_saved_theme(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true, 'theme' => 'light']));

        Livewire::test(SettingsPage::class)->assertSet('theme', 'light');
    }

    public function test_an_invalid_theme_is_ignored(): void
    {
        $user = User::factory()->create(['is_admin' => true, 'theme' => 'dark']);
        $this->actingAs($user);

        Livewire::test(SettingsPage::class)->call('setTheme', 'neon');

        $this->assertSame('dark', $user->fresh()?->theme);
    }

    public function test_a_non_admin_may_change_their_own_theme(): void
    {
        // Appearance is a personal choice, not an admin-gated system setting.
        $user = User::factory()->create(['is_admin' => false, 'theme' => null]);
        $this->actingAs($user);

        Livewire::test(SettingsPage::class)
            ->call('setTheme', 'system')
            ->assertSet('theme', 'system');

        $this->assertSame('system', $user->fresh()?->theme);
    }
}
