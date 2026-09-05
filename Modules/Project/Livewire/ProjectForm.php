<?php

declare(strict_types=1);

namespace Modules\Project\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Project\Models\Project;
use Livewire\Attributes\Locked;

/**
 * Create/edit a project via the engine FormView (auto-save on existing
 * records). Thin wrapper — mirrors PosCategoryForm.
 */
#[Layout('components.layouts.app')]
#[Title('Project')]
final class ProjectForm extends Component
{
    #[Locked]
    public ?int $id = null;

    public function mount(int|string|null $id = null): void
    {
        // A route segment is always a string, and a non-numeric one
        // ("new") means a new record rather than a bad request.
        $id = is_numeric($id) ? (int) $id : null;

        $this->id = $id;
    }

    public function render(): View
    {
        $project = $this->id !== null ? Project::query()->find($this->id) : null;

        return view('project::project-form', ['project' => $project]);
    }
}
