<?php

declare(strict_types=1);

namespace Modules\Project\Livewire;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Project\Models\Project;

/**
 * Project module landing — a card per project linking to its Kanban board.
 */
#[Layout('components.layouts.app')]
#[Title('Projects')]
final class ProjectHome extends Component
{
    public function render(): View
    {
        app(AccessControl::class)->authorize(Auth::user(), 'project.project', Permission::Read);

        $projects = Project::query()
            ->withCount('tasks')
            ->orderBy('name')
            ->get();

        return view('project::home', [
            'projects' => $projects,
            'canCreate' => app(AccessControl::class)
                ->allows(Auth::user(), 'project.project', Permission::Create),
        ]);
    }
}
