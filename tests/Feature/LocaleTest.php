<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Settings\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

/**
 * Locale wiring contract:
 *
 *   - **Per-user**: `Auth::user()->language` drives `app()->getLocale()`
 *     on every web request via the `SetLocale` middleware. Faraj on
 *     `ar` and Qassim on `en` simultaneously work as expected.
 *   - Users with no personal preference (NULL column, e.g. fresh
 *     factory rows) fall back to the system-wide `company.language`
 *     setting, which is also the locale of the guest /login page.
 *   - Unknown/empty values silently fall back to English so a misconfig
 *     can't 4xx the whole site.
 *   - Arabic flips the `<html>` element to `dir="rtl"` so logical CSS
 *     utilities mirror correctly.
 *   - Strings flow through `__()` so Blade keys resolve from `lang/ar.json`.
 *   - WhatsApp brand text and currency ISO codes stay literal regardless
 *     of locale (deliberate carve-outs).
 */
final class LocaleTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::flush();
    }

    public function test_default_locale_is_english_when_setting_is_missing(): void
    {
        // No company.language row → fallback to 'en'.
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        $this->get('/')->assertOk();

        $this->assertSame('en', app()->getLocale());
    }

    public function test_arabic_setting_pushes_locale_through_middleware(): void
    {
        Setting::set('company.language', 'ar');

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        $response = $this->get('/');
        $response->assertOk();

        $this->assertSame('ar', app()->getLocale());

        // dir="rtl" must be on <html> so logical utilities mirror the layout.
        $response->assertSee('dir="rtl"', false);
        // Translated chrome strings appear (Arabic for "Home").
        $response->assertSee('الرئيسية', false);
    }

    public function test_unknown_locale_falls_back_to_english(): void
    {
        Setting::set('company.language', 'zz');

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        $this->get('/')->assertOk();

        // Anything outside the whitelist (en/ar) must NOT silently park
        // an unsupported locale.
        $this->assertSame('en', app()->getLocale());
    }

    public function test_settings_page_offers_the_language_dropdown(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        $component = \Livewire\Livewire::test(\App\Livewire\Pages\SettingsPage::class);
        $selects = $component->get('selects');

        $this->assertArrayHasKey('company.language', $selects);

        $codes = array_column($selects['company.language'], 'value');
        $this->assertContains('en', $codes);
        $this->assertContains('ar', $codes);
    }

    public function test_changing_language_dispatches_browser_reload_event(): void
    {
        // Start on English, save with Arabic selected → page must reload
        // so the master layout's `dir` attribute and translated chrome
        // can be re-rendered from scratch.
        Setting::set('company.language', 'en');

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        $component = \Livewire\Livewire::test(\App\Livewire\Pages\SettingsPage::class);

        // Find the language row and flip its value to 'ar'.
        $form = $component->get('form');
        $langIndex = null;
        foreach ($form as $i => $row) {
            if ($row['key'] === 'company.language') {
                $langIndex = $i;
                break;
            }
        }
        $this->assertNotNull($langIndex, 'company.language must appear in $form');

        $component->set("form.{$langIndex}.value", 'ar')
            ->call('save')
            ->assertDispatched('language-changed');

        // …and the choice landed on the USER row, not the system setting
        // (so other users keep their own preference).
        $this->assertSame('ar', $admin->fresh()->language);
        $this->assertSame('en', Setting::get('company.language'));
    }

    public function test_saving_without_changing_language_does_not_trigger_reload(): void
    {
        // Hitting Save with the language unchanged must NOT reload — that
        // would be jarring for "just changed another setting" workflows.
        Setting::set('company.language', 'en');

        $admin = User::factory()->create(['is_admin' => true, 'language' => 'en']);
        $this->actingAs($admin);

        // No form mutations → save() persists the same value back, but the
        // before/after snapshot of the effective language matches, so no
        // browser event should be dispatched.
        \Livewire\Livewire::test(\App\Livewire\Pages\SettingsPage::class)
            ->call('save')
            ->assertNotDispatched('language-changed');
    }

    public function test_users_have_independent_language_preferences(): void
    {
        // The whole reason for the per-user routing: Faraj on `ar`
        // mustn't drag Qassim into Arabic. Two users sharing the same
        // host, each request resolves to that user's own locale.
        Setting::set('company.language', 'en');

        $faraj = User::factory()->create(['name' => 'Faraj', 'language' => 'ar']);
        $qassim = User::factory()->create(['name' => 'Qassim', 'language' => 'en']);

        $this->actingAs($faraj);
        $this->get('/')->assertOk();
        $this->assertSame('ar', app()->getLocale(), 'Faraj should see Arabic');

        $this->actingAs($qassim);
        $this->get('/')->assertOk();
        $this->assertSame('en', app()->getLocale(), 'Qassim should see English');
    }

    public function test_user_without_preference_inherits_system_default(): void
    {
        // NULL `users.language` (e.g. a freshly seeded account) falls
        // back to the system-wide setting — same path the login page
        // uses for a guest request.
        Setting::set('company.language', 'ar');

        $user = User::factory()->create(['language' => null]);
        $this->actingAs($user);

        $this->get('/')->assertOk();
        $this->assertSame('ar', app()->getLocale());
    }

    public function test_arabic_login_page_renders_translated_form(): void
    {
        Setting::set('company.language', 'ar');

        $this->get('/login')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            // "تسجيل الدخول" is "Sign in" — appears as both header and submit.
            ->assertSee('تسجيل الدخول', false)
            // "Email or username" → Arabic equivalent.
            ->assertSee('البريد الإلكتروني أو اسم المستخدم', false);
    }
}
