<?php

declare(strict_types=1);

namespace Modules\Project\Livewire;

use App\Erp\Navigation\ModuleMenu;
use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use App\Models\Ir\IrModule;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Project\Models\Project;

/**
 * Project module landing — a card per project linking to its Kanban board,
 * with inline edit / delete.
 */
#[Layout('components.layouts.app')]
#[Title('Projects')]
final class ProjectHome extends Component
{
    /**
     * Delete a project and everything under it. Unlink-gated.
     */
    public function deleteProject(int $id): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'project.project', Permission::Unlink);

        $project = Project::query()->find($id);

        if ($project === null) {
            return;
        }

        DB::transaction(function () use ($project): void {
            // Drop the analytic account (cascades its cost lines), then the
            // project (cascades stages, tasks and timesheets via FKs).
            $project->analyticAccount?->delete();
            $project->delete();
        });
    }

    public function render(): View
    {
        app(AccessControl::class)->authorize(Auth::user(), 'project.project', Permission::Read);

        $projects = Project::query()
            ->withCount('tasks')
            ->orderBy('name')
            ->get();

        $access = app(AccessControl::class);
        $user = Auth::user();

        // App-home tiles (the model "tabs" that used to live in the sidebar):
        // Project + Task, each respecting the viewer's Read ACL.
        $module = IrModule::query()->where('name', 'project')->first();
        $tiles = $module !== null ? app(ModuleMenu::class)->items($module, $user) : [];

        return view('project::home', [
            'projects' => $projects,
            'tiles' => $tiles,
            'canCreate' => $access->allows($user, 'project.project', Permission::Create),
            'canEdit' => $access->allows($user, 'project.project', Permission::Write),
            'canDelete' => $access->allows($user, 'project.project', Permission::Unlink),
        ]);
    }
}
