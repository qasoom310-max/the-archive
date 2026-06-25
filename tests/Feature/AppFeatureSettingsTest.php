<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Business\Feature;
use App\Erp\Business\Features;
use App\Erp\Modules\ModuleManager;
use App\Erp\Settings\Setting;
use App\Erp\Settings\SettingManager;
use App\Livewire\Navigation\AppSwitcher;
use App\Livewire\Pages\AppFeatureSettings;
use App\Models\User;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Per-app feature toggles: an app's own Settings tab turns its sub-features
 * on/off for the active database, overriding the business-type preset.
 */
final class AppFeatureSettingsTest extends TestCase
{
    use DatabaseMigrations;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function bootPos(): void
    {
        app(ModuleManager::class)->install('pos');
        (new SettingSeeder())->run();
        app(SettingManager::class)->flush();
    }

    public function test_admin_sees_the_pos_feature_toggles(): void
    {
        $this->actingAs($this->admin());
        $this->bootPos();

        Livewire::test(AppFeatureSettings::class, ['module' => 'pos'])
            ->assertOk()
            ->assertSee('Dine-in')   // Restaurant feature label
            ->assertSee('Recipes');  // Recipes feature label
    }

    public function test_turning_dine_in_off_hides_floors_and_tables_even_in_a_cafe(): void
    {
        $this->actingAs($this->admin());
        $this->bootPos();

        // Café preset enables dine-in...
        Setting::set('company.business_type', 'cafe');
        app(SettingManager::class)->flush();
        $this->assertTrue(Features::enabled(Feature::Restaurant));

        Livewire::test(AppFeatureSettings::class, ['module' => 'pos'])
            ->assertSet('toggles.restaurant', true)
            ->set('toggles.restaurant', false)
            ->call('save')
            ->assertSet('saved', true);

        app(SettingManager::class)->flush();

        // ...but the manual override wins: dine-in is off, floors/tables hidden.
        $this->assertFalse(Features::enabled(Feature::Restaurant));
        $this->assertFalse(Features::modelAllowed('pos.floor'));
        $this->assertFalse(Features::modelAllowed('pos.table'));
    }

    public function test_turning_dine_in_on_shows_it_even_in_a_retail_shop(): void
    {
        $this->actingAs($this->admin());
        $this->bootPos();

        // Retail preset hides dine-in.
        Setting::set('company.business_type', 'retail');
        app(SettingManager::class)->flush();
        $this->assertFalse(Features::presetEnabled(Feature::Restaurant));

        Livewire::test(AppFeatureSettings::class, ['module' => 'pos'])
            ->set('toggles.restaurant', true)
            ->call('save');

        app(SettingManager::class)->flush();

        $this->assertTrue(Features::enabled(Feature::Restaurant));
        $this->assertTrue(Features::modelAllowed('pos.floor'));
    }

    public function test_condiments_can_be_toggled_off_from_pos_settings(): void
    {
        $this->actingAs($this->admin());
        $this->bootPos();

        Setting::set('company.business_type', 'cafe');
        app(SettingManager::class)->flush();
        $this->assertTrue(Features::enabled(Feature::Condiments));
        $this->assertTrue(Features::modelAllowed('pos.condiment'));

        Livewire::test(AppFeatureSettings::class, ['module' => 'pos'])
            ->assertSee('Condiments')
            ->assertSee('Customer discounts')
            ->assertSee('Damage')
            ->assertSee('Barcode')
            ->set('toggles.condiments', false)
            ->call('save')
            ->assertSet('saved', true);

        app(SettingManager::class)->flush();
        $this->assertFalse(Features::enabled(Feature::Condiments));
        $this->assertFalse(Features::modelAllowed('pos.condiment'));
        // A sibling POS sub-feature is unaffected.
        $this->assertTrue(Features::enabled(Feature::CustomerDiscounts));
    }

    public function test_other_apps_expose_their_own_settings_tab(): void
    {
        // Accounting, Rent A Car and Limousine each gained a Settings tab...
        $this->assertNotEmpty(Features::appFeatures('accounting'));
        $this->assertNotEmpty(Features::appFeatures('rental'));
        $this->assertNotEmpty(Features::appFeatures('limousine'));
        // ...while single-purpose apps stay tab-less (no independent sub-feature).
        $this->assertSame([], Features::appFeatures('inventory'));
        $this->assertSame([], Features::appFeatures('purchases'));
        $this->assertSame([], Features::appFeatures('contacts'));
    }

    public function test_accounting_journal_entries_toggle_hides_only_that_model(): void
    {
        (new SettingSeeder())->run();
        Setting::set('company.business_type', 'cafe');
        app(SettingManager::class)->flush();

        $this->assertTrue(Features::modelAllowed('accounting.journal_entry'));
        $this->assertTrue(Features::modelAllowed('accounting.account'));

        Features::setOverrides(['journal_entries' => false]);
        app(SettingManager::class)->flush();

        $this->assertFalse(Features::modelAllowed('accounting.journal_entry'));
        // The chart of accounts stays visible.
        $this->assertTrue(Features::modelAllowed('accounting.account'));
    }

    public function test_rental_and_limousine_subfeatures_gate_their_models(): void
    {
        (new SettingSeeder())->run();
        Setting::set('company.business_type', 'rental_limousine');
        app(SettingManager::class)->flush();

        $this->assertTrue(Features::modelAllowed('rental.maintenance'));
        $this->assertTrue(Features::modelAllowed('limousine.expense'));

        Features::setOverrides(['rental_maintenance' => false, 'limo_expenses' => false]);
        app(SettingManager::class)->flush();

        $this->assertFalse(Features::modelAllowed('rental.maintenance'));
        $this->assertFalse(Features::modelAllowed('limousine.expense'));
        // Siblings unaffected by another sub-feature's override.
        $this->assertTrue(Features::modelAllowed('rental.driver'));
        $this->assertTrue(Features::modelAllowed('limousine.quotation'));
    }

    public function test_postpaid_is_off_by_default_and_opt_in(): void
    {
        app(SettingManager::class)->flush();

        // An unconfigured database fails open for ordinary features...
        $this->assertTrue(Features::enabled(Feature::Condiments));
        // ...but Postpaid is opt-in: the register is PREPAID (off) until an
        // admin turns it on — even when no business type is set.
        $this->assertFalse(Features::enabled(Feature::Postpaid));

        // A configured business type does NOT enable it either (not in any preset).
        Setting::set('company.business_type', 'cafe');
        app(SettingManager::class)->flush();
        $this->assertFalse(Features::enabled(Feature::Postpaid));

        // The POS → Settings toggle is what switches a restaurant to kitchen-first.
        Features::setOverrides(['postpaid' => true]);
        app(SettingManager::class)->flush();
        $this->assertTrue(Features::enabled(Feature::Postpaid));
    }

    public function test_daily_report_cards_can_be_toggled_off_from_pos_settings(): void
    {
        $this->actingAs($this->admin());
        $this->bootPos();

        // Shown by default (fail-open).
        $this->assertTrue(Features::enabled(Feature::DailyReportCards));

        Livewire::test(AppFeatureSettings::class, ['module' => 'pos'])
            ->assertSee('Daily sale')
            ->assertSet('toggles.daily_report_cards', true)
            ->set('toggles.daily_report_cards', false)
            ->call('save')
            ->assertSet('saved', true);

        app(SettingManager::class)->flush();
        $this->assertFalse(Features::enabled(Feature::DailyReportCards));
    }

    public function test_non_admin_is_forbidden(): void
    {
        $this->actingAs($this->admin());
        $this->bootPos();

        $this->actingAs(User::factory()->create(['is_admin' => false]));

        Livewire::test(AppFeatureSettings::class, ['module' => 'pos'])->assertForbidden();
    }

    public function test_an_app_without_feature_toggles_404s(): void
    {
        $this->actingAs($this->admin());
        $this->bootPos();

        // Contacts has no sub-features → no settings page.
        Livewire::test(AppFeatureSettings::class, ['module' => 'contacts'])->assertStatus(404);
    }

    public function test_app_switcher_links_settings_for_admins_only(): void
    {
        $this->actingAs($this->admin());
        $this->bootPos();

        Livewire::test(AppSwitcher::class)
            ->assertViewHas('settingsUrls', fn (array $u): bool => ($u['pos'] ?? null) !== null);

        $this->actingAs(User::factory()->create(['is_admin' => false]));
        Livewire::test(AppSwitcher::class)
            ->assertViewHas('settingsUrls', fn (array $u): bool => ($u['pos'] ?? null) === null);
    }

    public function test_overrides_key_is_not_shown_on_the_central_settings_page(): void
    {
        $this->actingAs($this->admin());
        $this->bootPos();

        // Create the overrides row, then confirm it never appears as a setting.
        Features::setOverrides(['restaurant' => false]);
        app(SettingManager::class)->flush();

        Livewire::test(\App\Livewire\Pages\SettingsPage::class)
            ->assertOk()
            ->assertViewHas('form', function (array $form): bool {
                foreach ($form as $row) {
                    if (($row['key'] ?? '') === 'features.overrides') {
                        return false;
                    }
                }

                return true;
            });
    }
}
