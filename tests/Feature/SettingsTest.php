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

        Livewire::test(SettingsPage::class)
            ->assertOk()
            ->assertSee('Company Name')
            ->set('form.0.value', 'My ERP') // company.name (sort 10 → index 0)
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
}
