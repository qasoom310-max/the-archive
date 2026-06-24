<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Settings\Setting;
use App\Erp\Settings\SettingManager;
use App\Livewire\Pages\SettingsPage;
use App\Models\Ir\IrConfigParameter;
use App\Models\User;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Tests\TestCase;

final class SettingsTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        // Cache is in-process; ensure a clean map per test.
        app(SettingManager::class)->flush();
    }

    public function test_get_set_round_trip_and_type_casting(): void
    {
        $this->seed(SettingSeeder::class);

        // Seeded default, read through the cache.
        $this->assertSame('OpenERP', Setting::get('company.name'));

        // set() must persist and flush the cache (next get is fresh).
        Setting::set('company.name', 'Acme Co');
        $this->assertSame('Acme Co', Setting::get('company.name'));
        $this->assertDatabaseHas('ir_config_parameter', [
            'key' => 'company.name', 'value' => 'Acme Co',
        ]);

        // Typed casting.
        IrConfigParameter::query()->create([
            'key' => 'pos.print', 'value' => '0', 'type' => 'bool',
            'group' => 'POS', 'label' => 'Receipt printing', 'sort' => 10,
        ]);
        IrConfigParameter::query()->create([
            'key' => 'pos.tax', 'value' => '14', 'type' => 'number',
            'group' => 'POS', 'label' => 'Tax %', 'sort' => 20,
        ]);
        app(SettingManager::class)->flush();

        $this->assertFalse(Setting::get('pos.print'));
        $this->assertSame(14, Setting::get('pos.tax'));

        Setting::set('pos.print', true);
        Setting::set('pos.tax', 14.5);
        $this->assertTrue(Setting::get('pos.print'));
        $this->assertSame(14.5, Setting::get('pos.tax'));
        $this->assertDatabaseHas('ir_config_parameter', ['key' => 'pos.print', 'value' => '1']);

        $this->assertSame('fallback', Setting::get('missing.key', 'fallback'));
    }

    public function test_admin_can_bulk_save_settings_and_cache_is_flushed(): void
    {
        $this->seed(SettingSeeder::class);
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        // Warm the cache with the old value.
        $this->assertSame('OpenERP', Setting::get('company.name'));

        $component = Livewire::test(SettingsPage::class)->assertOk()->assertSee('Company Name');

        // Locate company.name by key rather than a hard-coded index — new
        // setting groups (e.g. Business) sort ahead of General and shift it.
        /** @var list<array{key: string}> $form */
        $form = $component->get('form');
        $nameIndex = null;
        foreach ($form as $i => $row) {
            if ($row['key'] === 'company.name') {
                $nameIndex = $i;
                break;
            }
        }
        $this->assertNotNull($nameIndex, 'company.name row must be present in the seeded form');

        $component
            ->set("form.{$nameIndex}.value", 'My ERP')
            ->call('save')
            ->assertSet('saved', true);

        $this->assertDatabaseHas('ir_config_parameter', [
            'key' => 'company.name', 'value' => 'My ERP',
        ]);
        // Cache was flushed by the bulk save.
        $this->assertSame('My ERP', Setting::get('company.name'));
    }

    public function test_non_admin_can_open_settings_but_only_sees_language(): void
    {
        $this->seed(SettingSeeder::class);
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        $component = Livewire::test(SettingsPage::class)->assertOk();

        // Form must contain exactly the whitelisted keys.
        /** @var list<array{key: string}> $form */
        $form = $component->get('form');
        $this->assertSame(['company.language'], array_column($form, 'key'));

        // Admin-only labels (full setting names) must not appear at all.
        $component->assertDontSee('Company Name')
            ->assertDontSee('Default Currency')
            ->assertDontSee('Timezone')
            ->assertSee('Language');
    }

    public function test_non_admin_save_cannot_escalate_to_other_keys(): void
    {
        $this->seed(SettingSeeder::class);
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user);

        // Even if a crafted payload smuggles `company.name` into the
        // form array, save() must drop it. Simulate by setting the
        // language legitimately, then `$set`ing a forbidden row that
        // mount() wouldn't have produced.
        Livewire::test(SettingsPage::class)
            ->set('form.0.value', 'ar')
            ->set('form', [
                ['key' => 'company.language', 'label' => 'Language', 'type' => 'string', 'group' => 'General', 'description' => null, 'value' => 'ar'],
                ['key' => 'company.name', 'label' => 'Company Name', 'type' => 'string', 'group' => 'General', 'description' => null, 'value' => 'PWNED'],
            ])
            ->call('save')
            ->assertSet('saved', true);

        // Language flipped on the USER row (per-user routing), the
        // system company.language stays at its seeded default, and
        // company.name is untouched.
        $this->assertSame('ar', $user->fresh()->language);
        $this->assertSame('en', Setting::get('company.language'));
        $this->assertSame('OpenERP', Setting::get('company.name'));
    }

    // ───────────────────────── Company logo ─────────────────────────

    public function test_logo_url_is_null_when_no_setting(): void
    {
        // Fresh install: company.logo seeded as empty string. Logo::url()
        // must return null so the layouts fall back to text branding.
        $this->seed(SettingSeeder::class);

        $this->assertNull(\App\Erp\Branding\Logo::url());
    }

    public function test_logo_url_is_null_when_file_is_missing(): void
    {
        // Same regression guard as User::avatarUrl: a setting can point
        // at a now-gone file (deploy bucket wipe). Render falls back to
        // null instead of producing a broken-image URL.
        $this->seed(SettingSeeder::class);
        \Illuminate\Support\Facades\Storage::fake('public');
        Setting::set('company.logo', 'company/wiped.webp');

        $this->assertNull(\App\Erp\Branding\Logo::url());
    }

    public function test_logo_url_returns_url_when_setting_and_file_both_present(): void
    {
        $this->seed(SettingSeeder::class);
        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Storage::disk('public')->put('company/test.png', 'fake-bytes');
        Setting::set('company.logo', 'company/test.png');

        $url = \App\Erp\Branding\Logo::url();
        $this->assertNotNull($url);
        $this->assertStringContainsString('company/test.png', $url);
    }

    public function test_settings_page_save_persists_uploaded_logo_path(): void
    {
        // The image-type row's value is held in $imagePaths (populated
        // by the upload widget), not in $form[i].value. save() must
        // merge it into ir_config_parameter so the next page render
        // picks it up. imagePaths is INDEX-keyed (by row position)
        // because Livewire's set() treats dots as path traversal — the
        // setting key "company.logo" would otherwise be split.
        $this->seed(SettingSeeder::class);
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $component = Livewire::test(SettingsPage::class)->assertOk();

        // Find the row index of company.logo in $form (sort 15 → likely
        // index 1, but assert by lookup so a re-sort doesn't break us).
        /** @var list<array{key: string}> $form */
        $form = $component->get('form');
        $logoIndex = null;
        foreach ($form as $i => $row) {
            if ($row['key'] === 'company.logo') {
                $logoIndex = $i;
                break;
            }
        }
        $this->assertNotNull($logoIndex, 'company.logo row must be present in the seeded form');

        $component->set("imagePaths.{$logoIndex}", 'company/uploaded.webp')
            ->call('save')
            ->assertSet('saved', true);

        $this->assertSame('company/uploaded.webp', Setting::get('company.logo'));
    }

    // ───────────────────────── Timezone dropdown ─────────────────────────

    public function test_timezone_dropdown_is_populated_and_deduplicated(): void
    {
        // Saudi Arabia (Asia/Riyadh) and Bahrain (Asia/Bahrain) share
        // UTC+03:00 — the dropdown must collapse them into ONE row.
        // Total entries should sit well below the raw IANA count (400+).
        $this->seed(SettingSeeder::class);
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $component = Livewire::test(SettingsPage::class);
        /** @var array<string, list<array{value: string, label: string}>> $selects */
        $selects = $component->get('selects');

        $this->assertArrayHasKey('company.timezone', $selects);

        // Roughly 38 unique offsets in IANA's current dataset; assert a
        // tight band so a future PHP DST tweak doesn't flake the test.
        $this->assertGreaterThan(20, count($selects['company.timezone']));
        $this->assertLessThan(60, count($selects['company.timezone']));

        // UTC must be the offset-0 representative (seeded default
        // depends on this).
        $values = array_column($selects['company.timezone'], 'value');
        $this->assertContains('UTC', $values);

        // The UTC+03:00 row must mention multiple Gulf cities so a
        // user typing "Riyadh" still finds it even though the value
        // is e.g. "Asia/Bahrain". The first non-Etc alphabetical zone
        // for UTC+03:00 is Asia/Aden — but the label includes the
        // next several too.
        $labels = array_column($selects['company.timezone'], 'label');
        $utcPlus3 = array_values(array_filter($labels, static fn ($l): bool => str_starts_with($l, '(UTC+03:00)')));
        $this->assertNotEmpty($utcPlus3);
        // At least one well-known Gulf city should appear so search is
        // useful — Bahrain, Riyadh, Qatar, and Kuwait all sit here.
        $this->assertMatchesRegularExpression('/Bahrain|Riyadh|Qatar|Kuwait|Baghdad/', $utcPlus3[0]);
    }

    public function test_timezone_dropdown_is_hidden_from_non_admins(): void
    {
        // Cashiers / sales users land on the language-only view —
        // building the 400-entry IANA list for them is pointless
        // (and the row isn't even rendered).
        $this->seed(SettingSeeder::class);
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        $component = Livewire::test(SettingsPage::class);
        /** @var array<string, list<array{value: string, label: string}>> $selects */
        $selects = $component->get('selects');

        $this->assertArrayNotHasKey('company.timezone', $selects);
    }

    public function test_settings_page_save_without_new_upload_keeps_existing_logo(): void
    {
        // Re-saving the form without touching the logo input must NOT
        // blank the column — a no-op for the image row.
        $this->seed(SettingSeeder::class);
        Setting::set('company.logo', 'company/existing.png');
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(SettingsPage::class)
            ->call('save')
            ->assertSet('saved', true);

        $this->assertSame('company/existing.png', Setting::get('company.logo'));
    }
}
