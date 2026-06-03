<?php

declare(strict_types=1);

namespace Modules\Project\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Project\Models\Project;

/**
 * Projects browser — renders the metadata-driven engine List view for the
 * `project.project` model (backs the sidebar entry). Rows open the board.
 */
#[Layout('components.layouts.app')]
#[Title('Projects')]
final class Projects extends Component
{
    public function render(): View
    {
        return view('project::engine-list', [
            'model' => Project::class,
            'modelKey' => 'project.project',
        ]);
    }
}
