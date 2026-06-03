<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Project\Livewire\ProjectBoard;
use Modules\Project\Models\Project;
use Modules\Project\Models\ProjectStage;
use Modules\Project\Models\ProjectTask;
use Tests\TestCase;

/**
 * Pins ProjectBoard::moveTask — the drag-drop handler. It must restage a
 * card, renumber the destination column so order survives a refresh, stay
 * scoped to its own project, and ignore foreign tasks/stages.
 */
final class ProjectBoardTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        // Admin bypasses AccessControl, so the board's Write gate is open.
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('project');
    }

    private function makeProjectWithTwoStages(): Project
    {
        $project = Project::query()->create(['name' => 'Site']);
        ProjectStage::query()->create(['project_id' => $project->id, 'name' => 'To Do', 'sequence' => 0]);
        ProjectStage::query()->create(['project_id' => $project->id, 'name' => 'In Progress', 'sequence' => 1]);

        return $project;
    }

    public function test_move_task_restages_card_and_dispatches_event(): void
    {
        $project = $this->makeProjectWithTwoStages();
        [$todo, $doing] = $project->stages()->orderBy('sequence')->get()->all();

        $task = ProjectTask::query()->create([
            'project_id' => $project->id,
            'stage_id' => $todo->id,
            'title' => 'A',
            'sequence' => 0,
        ]);

        Livewire::test(ProjectBoard::class, ['project' => $project->id])
            ->call('moveTask', $task->id, $doing->id, 0)
            ->assertDispatched('task-moved');

        $task->refresh();
        $this->assertSame($doing->id, $task->stage_id);
        $this->assertSame(0, $task->sequence);
    }

    public function test_move_task_inserts_at_position_and_renumbers_column(): void
    {
        $project = $this->makeProjectWithTwoStages();
        $todo = $project->stages()->where('name', 'To Do')->sole();

        $a = ProjectTask::query()->create(['project_id' => $project->id, 'stage_id' => $todo->id, 'title' => 'A', 'sequence' => 0]);
        $b = ProjectTask::query()->create(['project_id' => $project->id, 'stage_id' => $todo->id, 'title' => 'B', 'sequence' => 1]);
        $c = ProjectTask::query()->create(['project_id' => $project->id, 'stage_id' => $todo->id, 'title' => 'C', 'sequence' => 2]);

        // Drag C to the top of the same column.
        Livewire::test(ProjectBoard::class, ['project' => $project->id])
            ->call('moveTask', $c->id, $todo->id, 0);

        $this->assertSame(0, $c->refresh()->sequence);
        $this->assertSame(1, $a->refresh()->sequence);
        $this->assertSame(2, $b->refresh()->sequence);
    }

    public function test_move_task_appends_when_sequence_overflows(): void
    {
        $project = $this->makeProjectWithTwoStages();
        [$todo, $doing] = $project->stages()->orderBy('sequence')->get()->all();

        $a = ProjectTask::query()->create(['project_id' => $project->id, 'stage_id' => $doing->id, 'title' => 'A', 'sequence' => 0]);
        $b = ProjectTask::query()->create(['project_id' => $project->id, 'stage_id' => $todo->id, 'title' => 'B', 'sequence' => 0]);

        // The blade column-drop passes 9999; the server clamps to the end.
        Livewire::test(ProjectBoard::class, ['project' => $project->id])
            ->call('moveTask', $b->id, $doing->id, 9999);

        $this->assertSame($doing->id, $b->refresh()->stage_id);
        $this->assertSame(0, $a->refresh()->sequence);
        $this->assertSame(1, $b->refresh()->sequence);
    }

    public function test_move_task_ignores_a_stage_from_another_project(): void
    {
        $project = $this->makeProjectWithTwoStages();
        $todo = $project->stages()->where('name', 'To Do')->sole();
        $task = ProjectTask::query()->create(['project_id' => $project->id, 'stage_id' => $todo->id, 'title' => 'A', 'sequence' => 0]);

        $other = Project::query()->create(['name' => 'Other']);
        $foreignStage = ProjectStage::query()->create(['project_id' => $other->id, 'name' => 'X', 'sequence' => 0]);

        Livewire::test(ProjectBoard::class, ['project' => $project->id])
            ->call('moveTask', $task->id, $foreignStage->id, 0);

        // Cross-project move rejected → still in its original column.
        $this->assertSame($todo->id, $task->refresh()->stage_id);
    }

    public function test_move_task_ignores_a_task_from_another_project(): void
    {
        $project = $this->makeProjectWithTwoStages();
        $doing = $project->stages()->where('name', 'In Progress')->sole();

        $other = Project::query()->create(['name' => 'Other']);
        $otherStage = ProjectStage::query()->create(['project_id' => $other->id, 'name' => 'X', 'sequence' => 0]);
        $foreignTask = ProjectTask::query()->create(['project_id' => $other->id, 'stage_id' => $otherStage->id, 'title' => 'F', 'sequence' => 0]);

        Livewire::test(ProjectBoard::class, ['project' => $project->id])
            ->call('moveTask', $foreignTask->id, $doing->id, 0);

        // The board can't reach a task outside its project.
        $this->assertSame($otherStage->id, $foreignTask->refresh()->stage_id);
    }
}
