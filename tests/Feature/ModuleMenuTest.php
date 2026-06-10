<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Enums\ModuleState;
use App\Erp\Navigation\ModuleMenu;
use App\Models\Auth\Group;
use App\Models\Auth\ModelAccess;
use App\Models\Demo\DemoTicket;
use App\Models\Ir\IrModel;
use App\Models\Ir\IrModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The shared source for both the contextual Sidebar and the Odoo-style
 * app-home tile dashboards: every registered model the user may Read,
 * mapped to its resource URL.
 */
final class ModuleMenuTest extends TestCase
{
    use RefreshDatabase;

    private function installShop(): IrModule
    {
        $module = IrModule::query()->create([
            'name' => 'shop',
            'display_name' => 'Shop',
            'version' => '1.0.0',
            'application' => true,
            'state' => ModuleState::Installed,
        ]);

        IrModel::query()->create([
            'model' => 'shop.order', 'name' => 'Shop Order', 'module' => 'shop',
            'class' => DemoTicket::class, 'table' => 'demo_tickets',
        ]);
        IrModel::query()->create([
            'model' => 'shop.secret', 'name' => 'Shop Secret', 'module' => 'shop',
            'class' => DemoTicket::class, 'table' => 'demo_tickets',
        ]);

        return $module;
    }

    public function test_admin_sees_every_model_with_resource_urls(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $module = $this->installShop();

        $items = app(ModuleMenu::class)->items($module, $admin);

        $this->assertCount(2, $items);
        // Ordered by name; slug drops the redundant module prefix.
        $this->assertSame('shop.order', $items[0]['model']);
        $this->assertSame(url('/app/shop/order'), $items[0]['url']);
        $this->assertSame(url('/app/shop/secret'), $items[1]['url']);
    }

    public function test_non_admin_only_sees_readable_models(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $group = Group::query()->create(['name' => 'Shop Staff', 'code' => 'shop_staff']);
        $user->groups()->attach($group);
        ModelAccess::query()->create([
            'name' => 'shop.order read', 'model' => 'shop.order', 'group_id' => $group->id,
            'perm_read' => true, 'perm_write' => false, 'perm_create' => false, 'perm_unlink' => false,
        ]);

        $module = $this->installShop();

        $items = app(ModuleMenu::class)->items($module, $user);

        // Only the granted model; the admin-only one is filtered out.
        $this->assertCount(1, $items);
        $this->assertSame('shop.order', $items[0]['model']);
    }

    public function test_guest_sees_nothing(): void
    {
        $module = $this->installShop();

        $this->assertSame([], app(ModuleMenu::class)->items($module, null));
    }

    public function test_module_home_renders_tiles_for_each_model(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->installShop();

        $this->get('/app/shop')
            ->assertOk()
            ->assertSee('Shop Order')
            ->assertSee('Shop Secret')
            ->assertSeeHtml('href="' . url('/app/shop/order') . '"');
    }
}
