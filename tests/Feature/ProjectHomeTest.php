<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Livewire\Navigation\Sidebar;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Project\Livewire\ProjectHome;
use Modules\Project\Models\AnalyticAccount;
use Modules\Project\Models\AnalyticLine;
use Modules\Project\Models\Project;
use Modules\Project\Models\ProjectStage;
use Modules\Project\Models\ProjectTask;
use Modules\Project\Models\ProjectTimesheet;
use Tests\TestCase;

/**
 * Covers the project landing: cascade delete from a card, and the sidebar
 * "Project" entry pointing at the module home rather than the engine list.
 */
final class ProjectHomeTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('project');
    }

    public function test_delete_project_removes_it_and_all_its_board_data(): void
    {
        $employee = User::factory()->create(['hourly_cost' => 20.0]);

        $project = Project::query()->create(['name' => 'Doomed']);
        $stage = ProjectStage::query()->create(['project_id' => $project->id, 'name' => 'Do', 'sequence' => 0]);
        $task = ProjectTask::query()->create(['project_id' => $project->id, 'stage_id' => $stage->id, 'title' => 'T']);
        ProjectTimesheet::query()->create([
            'task_id' => $task->id,
            'user_id' => $employee->id,
            'date' => '2026-06-03',
            'unit_amount' => 2.0,
            'name' => 'Work',
        ]);

        $analyticId = $project->fresh()?->analytic_account_id;
        $this->assertNotNull($analyticId);
        $this->assertSame(1, AnalyticLine::query()->count());

        Livewire::test(ProjectHome::class)->call('deleteProject', $project->id);

        $this->assertNull(Project::query()->find($project->id));
        $this->assertSame(0, ProjectStage::query()->where('project_id', $project->id)->count());
        $this->assertSame(0, ProjectTask::query()->where('project_id', $project->id)->count());
        $this->assertSame(0, ProjectTimesheet::query()->count());
        // Analytic account + its cost lines go too (clean removal).
        $this->assertNull(AnalyticAccount::query()->find($analyticId));
        $this->assertSame(0, AnalyticLine::query()->count());
    }

    public function test_delete_project_leaves_other_projects_untouched(): void
    {
        $keep = Project::query()->create(['name' => 'Keep']);
        $drop = Project::query()->create(['name' => 'Drop']);

        Livewire::test(ProjectHome::class)->call('deleteProject', $drop->id);

        $this->assertNull(Project::query()->find($drop->id));
        $this->assertNotNull(Project::query()->find($keep->id));
    }

    public function test_sidebar_project_entry_links_to_the_module_home(): void
    {
        Livewire::test(Sidebar::class, ['activeModule' => 'project'])
            // project.project → /app/project (not the redundant /app/project/project)
            ->assertSeeHtml('href="' . url('/app/project') . '"')
            ->assertDontSee(url('/app/project/project'))
            // project.task is unchanged.
            ->assertSeeHtml('href="' . url('/app/project/task') . '"');
    }
}
