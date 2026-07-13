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

    public function test_explicit_dark_renders_the_class_server_side_and_reapplies_on_navigation(): void
    {
        // A dark user's page must carry `.dark` on <html> from the SERVER so
        // wire:navigate's morph keeps it (else navigating reverts to light),
        // and the head script must re-apply on livewire:navigated.
        $this->actingAs(User::factory()->create(['is_admin' => true, 'theme' => 'dark']));

        $res = $this->get('/');

        $res->assertOk();
        $res->assertSee('data-theme="dark"', false);
        $this->assertMatchesRegularExpression('/<html[^>]*class="[^"]*\bdark\b/', $res->getContent());
        $res->assertSee('livewire:navigated', false);
    }

    public function test_light_does_not_render_the_dark_class(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true, 'theme' => 'light']));

        $res = $this->get('/');

        $res->assertOk();
        $this->assertDoesNotMatchRegularExpression('/<html[^>]*class="[^"]*\bdark\b/', $res->getContent());
    }

    public function test_setting_an_accent_persists_and_dispatches_live(): void
    {
        $user = User::factory()->create(['is_admin' => true, 'accent' => null]);
        $this->actingAs($user);

        Livewire::test(SettingsPage::class)
            ->assertSet('accent', 'yellow')   // null → default
            ->call('setAccent', 'sky')
            ->assertSet('accent', 'sky')
            ->assertDispatched('accent-changed', value: 'sky');

        $this->assertSame('sky', $user->fresh()?->accent);
    }

    public function test_an_invalid_accent_is_ignored(): void
    {
        $user = User::factory()->create(['is_admin' => true, 'accent' => 'emerald']);
        $this->actingAs($user);

        Livewire::test(SettingsPage::class)->call('setAccent', 'chartreuse');

        $this->assertSame('emerald', $user->fresh()?->accent);
    }

    public function test_the_accent_renders_on_the_html_element(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true, 'accent' => 'violet']));

        $res = $this->get('/');

        $res->assertOk();
        $res->assertSee('data-accent="violet"', false);
    }
}
