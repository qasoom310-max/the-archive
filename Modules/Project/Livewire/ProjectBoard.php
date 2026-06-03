<?php

declare(strict_types=1);

namespace Modules\Project\Livewire;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Project\Models\Project;
use Modules\Project\Models\ProjectStage;
use Modules\Project\Models\ProjectTask;

/**
 * The Kanban board for a single project: stages as columns, tasks as
 * draggable cards. The drag-drop handler ({@see moveTask()}) commits one
 * atomic write per drop — there is no "Save" button, matching the engine's
 * auto-save philosophy.
 */
#[Layout('components.layouts.app')]
#[Title('Project Board')]
final class ProjectBoard extends Component
{
    public int $projectId;

    /** Bound to the "add stage" input. */
    public string $newStageName = '';

    /**
     * Bound to each column's "add task" input, keyed by stage id.
     *
     * @var array<int, string>
     */
    public array $newTaskTitle = [];

    public function mount(int $project): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'project.task', Permission::Read);

        $this->projectId = $project;
    }

    /**
     * Append a new Kanban column to this project. Create-gated.
     */
    public function addStage(): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'project.task', Permission::Create);

        $name = trim($this->newStageName);

        if ($name === '') {
            return;
        }

        $maxSequence = (int) ProjectStage::query()
            ->where('project_id', $this->projectId)
            ->max('sequence');

        ProjectStage::query()->create([
            'project_id' => $this->projectId,
            'name' => $name,
            'sequence' => $maxSequence + 1,
        ]);

        $this->newStageName = '';
    }

    /**
     * Add a task to the bottom of a column. Create-gated and project-scoped.
     */
    public function addTask(int $stageId): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'project.task', Permission::Create);

        $stageBelongsToProject = ProjectStage::query()
            ->where('project_id', $this->projectId)
            ->whereKey($stageId)
            ->exists();

        if (! $stageBelongsToProject) {
            return;
        }

        $title = trim($this->newTaskTitle[$stageId] ?? '');

        if ($title === '') {
            return;
        }

        $maxSequence = (int) ProjectTask::query()
            ->where('project_id', $this->projectId)
            ->where('stage_id', $stageId)
            ->max('sequence');

        ProjectTask::query()->create([
            'project_id' => $this->projectId,
            'stage_id' => $stageId,
            'title' => $title,
            'sequence' => $maxSequence + 1,
        ]);

        $this->newTaskTitle[$stageId] = '';
    }

    /**
     * Move a task to a new stage at a target position, then renumber the
     * destination column so the order persists. Runs whenever a card is
     * dropped. Write-gated and scoped to THIS project so a crafted payload
     * can't move tasks across projects.
     */
    public function moveTask(int $taskId, int $newStageId, int $newSequence): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'project.task', Permission::Write);

        $task = ProjectTask::query()
            ->where('project_id', $this->projectId)
            ->whereKey($taskId)
            ->first();

        if ($task === null) {
            return;
        }

        $stageBelongsToProject = ProjectStage::query()
            ->where('project_id', $this->projectId)
            ->whereKey($newStageId)
            ->exists();

        if (! $stageBelongsToProject) {
            return;
        }

        $fromStageId = $task->stage_id;

        DB::transaction(function () use ($task, $newStageId, $newSequence): void {
            $task->stage_id = $newStageId;
            $task->save();

            // Rebuild the destination column's order with the moved task
            // spliced in at the requested position, then write contiguous
            // 0..n sequences. One UPDATE per sibling, all inside the tx.
            /** @var list<int> $orderedIds */
            $orderedIds = ProjectTask::query()
                ->where('project_id', $this->projectId)
                ->where('stage_id', $newStageId)
                ->whereKeyNot($task->getKey())
                ->orderBy('sequence')->orderBy('id')
                ->pluck('id')->all();

            $position = max(0, min($newSequence, count($orderedIds)));
            array_splice($orderedIds, $position, 0, [(int) $task->getKey()]);

            foreach ($orderedIds as $index => $id) {
                ProjectTask::query()->whereKey($id)->update(['sequence' => $index]);
            }
        });

        // Chatter trail on stage changes (ProjectTask is Chatterable).
        if ($fromStageId !== $newStageId) {
            $task->logChange("Stage #{$fromStageId} → #{$newStageId}");
        }

        $this->dispatch('task-moved', id: $taskId);
    }

    public function render(): View
    {
        $project = Project::query()->findOrFail($this->projectId);

        $stages = ProjectStage::query()
            ->where('project_id', $this->projectId)
            ->orderBy('sequence')->orderBy('id')
            ->get();

        // Top-level cards only (sub-tasks roll up under their parent). The
        // timesheet-hours SUM is eager-loaded so effective_hours /
        // remaining_hours don't fire a query per card.
        $tasks = ProjectTask::query()
            ->where('project_id', $this->projectId)
            ->whereNull('parent_id')
            ->with('assignee:id,name')
            ->withSum('timesheets', 'unit_amount')
            ->withCount('subtasks')
            ->orderBy('sequence')->orderBy('id')
            ->get();

        return view('project::board', [
            'project' => $project,
            'stages' => $stages,
            'tasksByStage' => $tasks->groupBy('stage_id'),
            'canWrite' => app(AccessControl::class)
                ->allows(Auth::user(), 'project.task', Permission::Write),
        ]);
    }
}
