<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Branding\Appearance;
use App\Erp\Settings\Setting;
use App\Erp\Settings\SettingManager;
use App\Erp\Tenancy\WorkspaceManager;
use App\Livewire\Pages\SettingsPage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Appearance (theme + accent) belongs to the DATABASE, not the account: Wanaan
 * is yellow + light while Kaleem picks its own colour + dark, and everyone who
 * uses a database sees the same look. Stored as per-workspace settings, and only
 * a SUPER admin may change them.
 */
final class ThemeSettingTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingManager::class)->flush();
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['is_admin' => true, 'is_super_admin' => true]);
    }

    public function test_a_super_admin_sets_the_theme_for_the_database(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(SettingsPage::class)
            ->call('setTheme', 'dark')
            ->assertSet('theme', 'dark')
            ->assertDispatched('theme-changed', value: 'dark');

        $this->assertSame('dark', Setting::get(Appearance::THEME_KEY));
        $this->assertSame('dark', Appearance::theme());
    }

    public function test_a_super_admin_sets_the_accent_for_the_database(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(SettingsPage::class)
            ->assertSet('accent', 'yellow')   // default
            ->call('setAccent', 'sky')
            ->assertSet('accent', 'sky')
            ->assertDispatched('accent-changed', value: 'sky');

        $this->assertSame('sky', Appearance::accent());
    }

    public function test_a_regular_admin_cannot_change_the_appearance(): void
    {
        Setting::set(Appearance::THEME_KEY, 'light');
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => false]));

        Livewire::test(SettingsPage::class)->call('setTheme', 'dark')->assertForbidden();

        $this->assertSame('light', Appearance::theme());
    }

    public function test_a_cashier_cannot_change_the_appearance(): void
    {
        Setting::set(Appearance::ACCENT_KEY, 'yellow');
        $this->actingAs(User::factory()->create(['is_admin' => false, 'is_super_admin' => false]));

        Livewire::test(SettingsPage::class)->call('setAccent', 'violet')->assertForbidden();

        $this->assertSame('yellow', Appearance::accent());
    }

    public function test_only_a_super_admin_sees_the_appearance_widget(): void
    {
        $blurb = 'Theme and accent colour for this database';

        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => false]));
        Livewire::test(SettingsPage::class)->assertDontSee($blurb);

        $this->actingAs($this->superAdmin());
        Livewire::test(SettingsPage::class)->assertSee($blurb);
    }

    public function test_an_invalid_theme_or_accent_is_ignored(): void
    {
        Setting::set(Appearance::THEME_KEY, 'dark');
        Setting::set(Appearance::ACCENT_KEY, 'emerald');
        $this->actingAs($this->superAdmin());

        Livewire::test(SettingsPage::class)
            ->call('setTheme', 'neon')
            ->call('setAccent', 'chartreuse');

        $this->assertSame('dark', Appearance::theme());
        $this->assertSame('emerald', Appearance::accent());
    }

    public function test_dark_renders_server_side_and_reapplies_on_navigation(): void
    {
        // The `.dark` class must come from the SERVER so wire:navigate's morph
        // keeps it (else navigating reverts to light).
        Setting::set(Appearance::THEME_KEY, 'dark');
        $this->actingAs($this->superAdmin());

        $res = $this->get('/');

        $res->assertOk();
        $res->assertSee('data-theme="dark"', false);
        $this->assertMatchesRegularExpression('/<html[^>]*class="[^"]*\bdark\b/', $res->getContent());
        $res->assertSee('livewire:navigated', false);
    }

    public function test_light_does_not_render_the_dark_class(): void
    {
        Setting::set(Appearance::THEME_KEY, 'light');
        $this->actingAs($this->superAdmin());

        $res = $this->get('/');

        $res->assertOk();
        $this->assertDoesNotMatchRegularExpression('/<html[^>]*class="[^"]*\bdark\b/', $res->getContent());
    }

    public function test_the_accent_renders_on_the_html_element(): void
    {
        Setting::set(Appearance::ACCENT_KEY, 'violet');
        $this->actingAs($this->superAdmin());

        $this->get('/')->assertOk()->assertSee('data-accent="violet"', false);
    }

    public function test_an_unset_database_falls_back_to_light_and_yellow(): void
    {
        $this->assertSame('light', Appearance::theme());
        $this->assertSame('yellow', Appearance::accent());
    }

    /**
     * The whole point: each database keeps its OWN look. Wanaan (Main) stays
     * yellow + light while Kaleem is sky + dark — neither leaks into the other.
     */
    public function test_each_database_keeps_its_own_theme_and_accent(): void
    {
        $manager = app(WorkspaceManager::class);

        // Main ("Wanaan"): yellow + light.
        Setting::set(Appearance::THEME_KEY, 'light');
        Setting::set(Appearance::ACCENT_KEY, 'yellow');

        $owner = User::factory()->create(['is_admin' => true, 'is_super_admin' => true, 'email' => 'owner@wanaan.test']);
        $kaleem = $manager->provision('Kaleem', $owner, ['contacts']);

        // Kaleem: sky + dark.
        $manager->withTenant((string) $kaleem->databasePath(), function (): void {
            Setting::set(Appearance::THEME_KEY, 'dark');
            Setting::set(Appearance::ACCENT_KEY, 'sky');

            $this->assertSame('dark', Appearance::theme());
            $this->assertSame('sky', Appearance::accent());
        });

        // Main is untouched.
        $this->assertSame('light', Appearance::theme());
        $this->assertSame('yellow', Appearance::accent());

        // And Kaleem still holds its own after Main was read back.
        $manager->withTenant((string) $kaleem->databasePath(), function (): void {
            $this->assertSame('dark', Appearance::theme());
            $this->assertSame('sky', Appearance::accent());
        });
    }

    /**
     * Dark mode is built by remapping the neutral utility CLASSES under `.dark`
     * instead of editing hundreds of views — which holds only while every
     * neutral background a view uses has a counterpart in that block.
     *
     * Two did not. `group-hover:bg-chrome-50`, on the pinned Actions column of
     * the trip queue. Remapping `.hover\:bg-*:hover` does nothing for it,
     * because hover driven by a PARENT compiles to a different selector — so
     * the pinned cell, which carries its own background in order to sit above
     * the row scrolling under it, was repainted from the LIGHT palette and
     * flashed white the moment the mouse crossed the row.
     *
     * And `disabled:bg-*`, which compiles to its own selector as well — a locked
     * field kept the light grey and sat as a pale block in a dark form.
     *
     * Reading the stylesheet from a test is unusual, but the rule being checked
     * is that the views and that block agree, and nothing else checked it.
     */
    public function test_every_neutral_hover_background_a_view_uses_is_remapped_for_dark(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $used = [];
        foreach (['resources/views', 'Modules'] as $dir) {
            /** @var iterable<\SplFileInfo> $files */
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }

                preg_match_all(
                    '/\b(group-hover|hover|disabled):(bg-(?:white|chrome-\d+))\b/',
                    (string) file_get_contents($file->getPathname()),
                    $matches,
                    PREG_SET_ORDER
                );

                foreach ($matches as $m) {
                    $used[$m[1] . ':' . $m[2]] = true;
                }
            }
        }

        $this->assertNotSame([], $used, 'Found no hover backgrounds at all — the scan itself is broken.');

        $missing = [];
        foreach (array_keys($used) as $class) {
            [$variant, $utility] = explode(':', $class, 2);

            // How Tailwind compiles each variant, and so what `.dark` has to
            // out-specify to win.
            $selector = $variant === 'group-hover'
                ? '.dark .group:hover .group-hover\\:' . $utility
                : '.dark .hover\\:' . $utility . ':hover';

            if (! str_contains($css, $selector . ' ')) {
                $missing[] = $class . '  (needs: ' . $selector . ')';
            }
        }

        sort($missing);

        $this->assertSame([], $missing, sprintf(
            "%d hover background(s) used in views have no dark remap, so they repaint in the light palette:\n  - %s",
            count($missing),
            implode("\n  - ", $missing)
        ));
    }
}
