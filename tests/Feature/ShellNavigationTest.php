<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Enums\ModuleState;
use App\Livewire\Navigation\AppSwitcher;
use App\Livewire\Navigation\CommandPalette;
use App\Models\Demo\DemoTicket;
use App\Models\Ir\IrModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class ShellNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function installApp(string $name, string $display): IrModule
    {
        return IrModule::query()->create([
            'name' => $name,
            'display_name' => $display,
            'version' => '1.0.0',
            'application' => true,
            'state' => ModuleState::Installed,
        ]);
    }

    public function test_dashboard_route_renders(): void
    {
        DemoTicket::query()->create(['subject' => 'Acme', 'stage' => 'New']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Dashboard')
            ->assertSeeLivewire(CommandPalette::class)
            ->assertSeeLivewire(AppSwitcher::class);
    }

    public function test_module_page_404s_for_unknown_module(): void
    {
        $this->get('/app/does-not-exist')->assertNotFound();
    }

    public function test_module_page_renders_for_installed_app(): void
    {
        $this->installApp('crm', 'CRM');

        $this->get('/app/crm')->assertOk()->assertSee('CRM');
    }

    public function test_app_switcher_lists_only_installed_applications(): void
    {
        $this->installApp('crm', 'CRM');
        IrModule::query()->create([
            'name' => 'hidden',
            'display_name' => 'Hidden Lib',
            'version' => '1.0.0',
            'application' => false,
            'state' => ModuleState::Installed,
        ]);

        Livewire::test(AppSwitcher::class)
            ->assertSee('CRM')
            ->assertDontSee('Hidden Lib');
    }

    public function test_command_palette_fuzzy_search(): void
    {
        $this->installApp('crm', 'CRM');
        $this->installApp('inventory', 'Inventory');

        Livewire::test(CommandPalette::class)
            ->set('query', 'inv')
            ->assertSee('Inventory')
            ->assertDontSee('CRM');
    }
}
