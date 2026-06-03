<?php

declare(strict_types=1);

namespace Modules\Project\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Project\Models\Project;

/**
 * Create/edit a project via the engine FormView (auto-save on existing
 * records). Thin wrapper — mirrors PosCategoryForm.
 */
#[Layout('components.layouts.app')]
#[Title('Project')]
final class ProjectForm extends Component
{
    public ?int $id = null;

    public function mount(?int $id = null): void
    {
        $this->id = $id;
    }

    public function render(): View
    {
        $project = $this->id !== null ? Project::query()->find($this->id) : null;

        return view('project::project-form', ['project' => $project]);
    }
}
