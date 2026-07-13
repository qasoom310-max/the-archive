<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Branding\Logo;
use App\Erp\Settings\Setting;
use App\Erp\Settings\SettingManager;
use App\Livewire\Pages\SettingsPage;
use App\Models\User;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Logo style: each database chooses how its brand logo is framed in the topbar
 * — the uploaded shape ("normal") or cropped to a round badge ("circle").
 */
final class LogoStyleTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingManager::class)->flush();
    }

    public function test_the_shape_defaults_to_normal_when_unset(): void
    {
        $this->seed(SettingSeeder::class);

        $this->assertSame(Logo::SHAPE_NORMAL, Logo::shape());
        $this->assertFalse(Logo::isCircle());
    }

    public function test_an_unseeded_database_still_falls_back_to_normal(): void
    {
        // No SettingSeeder → the row is absent entirely; must not blow up.
        $this->assertSame(Logo::SHAPE_NORMAL, Logo::shape());
        $this->assertFalse(Logo::isCircle());
    }

    public function test_choosing_circle_switches_the_shape(): void
    {
        $this->seed(SettingSeeder::class);
        Setting::set('company.logo_shape', Logo::SHAPE_CIRCLE);

        $this->assertSame(Logo::SHAPE_CIRCLE, Logo::shape());
        $this->assertTrue(Logo::isCircle());
    }

    public function test_the_settings_page_offers_the_logo_style_picker_to_an_admin(): void
    {
        $this->seed(SettingSeeder::class);
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $component = Livewire::test(SettingsPage::class)->assertSee('Logo style');

        // The picker's options are JSON-encoded into an Alpine attribute, so
        // assert the component state rather than the rendered markup.
        $selects = $component->get('selects');
        $this->assertArrayHasKey('company.logo_shape', $selects);
        $this->assertSame(
            [Logo::SHAPE_NORMAL, Logo::SHAPE_CIRCLE],
            array_column($selects['company.logo_shape'], 'value'),
        );
    }
}
