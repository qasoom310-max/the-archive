<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Business\BusinessType;
use App\Erp\Business\Feature;
use App\Erp\Business\Features;
use App\Erp\Settings\Setting;
use App\Erp\Settings\SettingManager;
use App\Livewire\Pages\SettingsPage;
use App\Models\User;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Tests\TestCase;

final class BusinessTypeTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingManager::class)->flush();
    }

    public function test_presets_distinguish_business_types(): void
    {
        // A café assembles items from components on sale → recipes on.
        $this->assertContains(Feature::Recipes, BusinessType::Cafe->features());
        // A shop sells finished goods → POS yes, recipes no.
        $this->assertNotContains(Feature::Recipes, BusinessType::Retail->features());
        $this->assertContains(Feature::Pos, BusinessType::Retail->features());
        // A hybrid shop (perfume house) resells AND crafts → POS + recipes.
        $this->assertContains(Feature::Pos, BusinessType::RetailCraft->features());
        $this->assertContains(Feature::Recipes, BusinessType::RetailCraft->features());
        // A services business has no sales counter at all.
        $this->assertNotContains(Feature::Pos, BusinessType::Services->features());
        // General is the everything-on superset.
        $this->assertSame(Feature::cases(), BusinessType::General->features());
    }

    public function test_rental_limousine_shows_both_apps_together(): void
    {
        // A business running BOTH books in one database: the rental app and
        // the limousine app must both survive the app-bar filter, while a
        // single-purpose type still hides the other.
        $this->seed(SettingSeeder::class);
        Setting::set('company.business_type', 'rental_limousine');

        $this->assertSame(BusinessType::RentalLimousine, Features::configuredType());
        $this->assertTrue(Features::moduleAllowed('rental'));
        $this->assertTrue(Features::moduleAllowed('limousine'));

        // A plain limousine database still hides the rental app.
        app(SettingManager::class)->flush();
        Setting::set('company.business_type', 'limousine');
        $this->assertFalse(Features::moduleAllowed('rental'));
        $this->assertTrue(Features::moduleAllowed('limousine'));
    }

    public function test_dine_in_features_are_cafe_only(): void
    {
        // Floors, tables and the kitchen/shisha screens are restaurant service —
        // a café has them; a retail shop and a perfume-crafting shop do not.
        $this->seed(SettingSeeder::class);

        Setting::set('company.business_type', 'cafe');
        $this->assertTrue(Features::enabled(Feature::Restaurant));
        $this->assertTrue(Features::enabled(Feature::Kitchen));
        $this->assertTrue(Features::modelAllowed('pos.floor'));
        $this->assertTrue(Features::modelAllowed('pos.table'));

        foreach (['retail', 'retail_craft'] as $type) {
            app(SettingManager::class)->flush();
            Setting::set('company.business_type', $type);

            $this->assertFalse(Features::enabled(Feature::Restaurant), $type);
            $this->assertFalse(Features::enabled(Feature::Kitchen), $type);
            $this->assertFalse(Features::modelAllowed('pos.floor'), $type);
            $this->assertFalse(Features::modelAllowed('pos.table'), $type);
            // The register itself still works in retail/crafting.
            $this->assertTrue(Features::moduleAllowed('pos'), $type);
        }
    }

    public function test_unconfigured_database_enables_everything(): void
    {
        // Legacy / freshly-seeded install: business_type is empty. Nothing
        // a business already uses may disappear.
        $this->seed(SettingSeeder::class);

        $this->assertNull(Features::configuredType());
        $this->assertTrue(Features::enabled(Feature::Recipes));
        $this->assertTrue(Features::modelAllowed('pos.ingredient'));
        $this->assertTrue(Features::moduleAllowed('pos'));
    }

    public function test_retail_hides_recipes_but_keeps_pos_and_inventory(): void
    {
        $this->seed(SettingSeeder::class);
        Setting::set('company.business_type', 'retail');

        $this->assertSame(BusinessType::Retail, Features::configuredType());
        $this->assertFalse(Features::enabled(Feature::Recipes));
        $this->assertFalse(Features::modelAllowed('pos.ingredient'));
        $this->assertTrue(Features::moduleAllowed('pos'));
        $this->assertTrue(Features::moduleAllowed('inventory'));
    }

    public function test_services_hides_the_sales_counter(): void
    {
        $this->seed(SettingSeeder::class);
        Setting::set('company.business_type', 'services');

        $this->assertFalse(Features::moduleAllowed('pos'));
        $this->assertFalse(Features::moduleAllowed('inventory'));
        $this->assertTrue(Features::moduleAllowed('accounting'));
        // Unmapped modules (contacts) are always shown.
        $this->assertTrue(Features::moduleAllowed('contacts'));
    }

    public function test_unknown_type_value_falls_back_to_everything_on(): void
    {
        // A stray / unrecognised setting must not strand the database with
        // a half-hidden UI — treat it like "not configured".
        $this->seed(SettingSeeder::class);
        Setting::set('company.business_type', 'spaceship');

        $this->assertNull(Features::configuredType());
        $this->assertTrue(Features::enabled(Feature::Recipes));
    }

    public function test_settings_page_exposes_business_picker_to_super_admin(): void
    {
        // Business type reshapes the whole app surface, so it's an owner-level
        // (super-admin) decision — see SettingsPage::SUPER_ADMIN_KEYS.
        $this->seed(SettingSeeder::class);
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => true]));

        $component = Livewire::test(SettingsPage::class)->assertOk()->assertSee('Business Type');

        /** @var array<string, list<array{value: string, label: string}>> $selects */
        $selects = $component->get('selects');
        $this->assertArrayHasKey('company.business_type', $selects);

        $values = array_column($selects['company.business_type'], 'value');
        $this->assertContains('cafe', $values);
        $this->assertContains('retail', $values);
    }

    public function test_business_picker_is_super_admin_only(): void
    {
        $this->seed(SettingSeeder::class);

        // A regular admin no longer sees it (moved up to the super admin).
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => false]));
        $admin = Livewire::test(SettingsPage::class)->assertOk();
        /** @var array<string, list<array{value: string, label: string}>> $adminSelects */
        $adminSelects = $admin->get('selects');
        $this->assertArrayNotHasKey('company.business_type', $adminSelects);
        $admin->assertDontSee('Business Type');

        // Neither does a plain staff user.
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $staff = Livewire::test(SettingsPage::class)->assertOk();
        /** @var array<string, list<array{value: string, label: string}>> $staffSelects */
        $staffSelects = $staff->get('selects');
        $this->assertArrayNotHasKey('company.business_type', $staffSelects);
        $staff->assertDontSee('Business Type');
    }
}
