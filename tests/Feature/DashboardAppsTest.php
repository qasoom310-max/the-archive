<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Erp\Settings\Setting;
use App\Erp\Settings\SettingManager;
use App\Livewire\Pages\Dashboard;
use App\Models\User;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Dashboard's "Your apps" launcher: one button per installed application
 * the active database's business type allows — the same filtering as the top
 * app bar, surfaced as big buttons for quick access.
 */
final class DashboardAppsTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');
        app(ModuleManager::class)->install('limousine');
        $this->seed(SettingSeeder::class);
        app(SettingManager::class)->flush();
    }

    public function test_dashboard_shows_a_button_per_allowed_app(): void
    {
        Setting::set('company.business_type', 'rental_limousine');

        Livewire::test(Dashboard::class)
            ->assertOk()
            ->assertSee('Your apps')
            ->assertSee('Rent A Car')
            ->assertSee('Limousine')
            ->assertViewHas('apps', function (Collection $apps): bool {
                $names = $apps->pluck('name')->all();

                return in_array('rental', $names, true) && in_array('limousine', $names, true);
            });
    }

    public function test_daily_report_cards_toggle_shows_and_hides_them_on_the_dashboard(): void
    {
        // POS installed → its tables exist, so the Daily sale / stock cards render.
        app(ModuleManager::class)->install('pos');
        app(SettingManager::class)->flush();

        Livewire::test(Dashboard::class)
            ->assertOk()
            ->assertSee('Daily sale')
            ->assertSee('Daily stock report');

        // Hide them from POS → Settings.
        \App\Erp\Business\Features::setOverrides(['daily_report_cards' => false]);
        app(SettingManager::class)->flush();

        Livewire::test(Dashboard::class)
            ->assertOk()
            ->assertDontSee('Daily sale');
    }

    public function test_business_type_filters_which_app_buttons_appear(): void
    {
        // A plain rental database shows Rent A Car but not Limousine.
        Setting::set('company.business_type', 'rental');

        Livewire::test(Dashboard::class)
            ->assertSee('Rent A Car')
            ->assertDontSee('Limousine')
            ->assertViewHas('apps', fn (Collection $apps): bool => $apps->pluck('name')->doesntContain('limousine'));
    }
}
