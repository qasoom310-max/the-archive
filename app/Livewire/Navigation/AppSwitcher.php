<?php

declare(strict_types=1);

namespace App\Livewire\Navigation;

use App\Erp\Business\Features;
use App\Erp\Enums\ModuleState;
use App\Erp\Navigation\ModuleMenu;
use App\Models\Ir\IrModule;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * App bar: an always-visible inline row of every installed
 * *application* module, read live from the ir_module registry, rendered
 * directly in the topbar (replaced the old 9-square grid dropdown). The
 * app whose screen is currently open is highlighted via $activeModule.
 */
final class AppSwitcher extends Component
{
    /** Slug of the module currently being viewed (/app/{module}/…), or null. */
    public ?string $activeModule = null;

    public function render(): View
    {
        /** @var Collection<int, IrModule> $apps */
        $apps = IrModule::query()
            ->where('application', true)
            ->where('state', ModuleState::Installed)
            ->orderBy('sequence')
            ->get()
            // Drop apps the active database's business type switches off
            // (e.g. a services business shows no Point of Sale).
            ->filter(fn (IrModule $app): bool => Features::moduleAllowed($app->name))
            ->values();

        // Each app's dropdown = the same ACL-filtered model menu the app-home
        // tile dashboard renders (one shared source — they can't drift). Apps
        // that register no DefinesIrModel (Inventory, Settings, …) get an
        // empty list and fall through to a plain home link in the view.
        $menu = app(ModuleMenu::class);
        $user = Auth::user();
        $isAdmin = $user instanceof User && $user->isAdmin();

        /** @var array<string, list<array{label: string, model: string, slug: string, url: string}>> $menus */
        $menus = [];
        // Per-app "Settings" deep-link — only for apps that expose feature
        // toggles AND only for admins (the page itself re-gates). Lets an admin
        // turn parts of an app on/off (e.g. POS dine-in) from the app's dropdown.
        /** @var array<string, string|null> $settingsUrls */
        $settingsUrls = [];
        foreach ($apps as $app) {
            $menus[$app->name] = $menu->items($app, $user);
            $settingsUrls[$app->name] = ($isAdmin && $user instanceof User && $user->mayAdministerApp($app->name) && Features::appFeatures($app->name) !== [])
                ? url('/app/' . $app->name . '/settings')
                : null;
        }

        return view('livewire.navigation.app-switcher', [
            'apps' => $apps,
            'menus' => $menus,
            'settingsUrls' => $settingsUrls,
        ]);
    }
}
