<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Project\Models\AnalyticLine;
use Modules\Project\Models\Project;
use Modules\Project\Models\ProjectStage;
use Modules\Project\Models\ProjectTask;
use Modules\Project\Models\ProjectTimesheet;
use Tests\TestCase;

/**
 * Pins the timesheet → analytic cost hook (TimesheetCostPoster, fired from
 * ProjectTimesheet's saved/deleted model events) and the effective/remaining
 * hours accessors. The hook is silent in production (errors are swallowed),
 * so a regression would only surface as "the analytic balance is wrong" —
 * exactly what these tests catch.
 */
final class ProjectTimesheetCostTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('project');
    }

    private function taskOnCostedProject(float $plannedHours = 10.0): ProjectTask
    {
        // auto_create (default on) gives the project an analytic account.
        $project = Project::query()->create(['name' => 'Build']);
        $stage = ProjectStage::query()->create(['project_id' => $project->id, 'name' => 'Do', 'sequence' => 0]);

        return ProjectTask::query()->create([
            'project_id' => $project->id,
            'stage_id' => $stage->id,
            'title' => 'T',
            'planned_hours' => $plannedHours,
        ]);
    }

    private function refOf(ProjectTimesheet $timesheet): string
    {
        return 'project.timesheet:' . $timesheet->id;
    }

    public function test_saving_a_timesheet_posts_a_negative_analytic_cost_line(): void
    {
        $task = $this->taskOnCostedProject();
        $employee = User::factory()->create(['hourly_cost' => 20.0]);

        $timesheet = ProjectTimesheet::query()->create([
            'task_id' => $task->id,
            'user_id' => $employee->id,
            'date' => '2026-06-03',
            'unit_amount' => 2.5,
            'name' => 'Wiring',
        ]);

        $line = AnalyticLine::query()->where('source_ref', $this->refOf($timesheet))->first();

        $this->assertNotNull($line);
        $this->assertSame(-50.0, $line->amount);           // 2.5h × 20 = 50, stored negative
        $this->assertSame(2.5, $line->unit_amount);
        $this->assertSame((int) $task->project->analytic_account_id, $line->analytic_account_id);
        $this->assertSame('Wiring', $line->name);
    }

    public function test_resaving_a_timesheet_updates_the_same_line_idempotently(): void
    {
        $task = $this->taskOnCostedProject();
        $employee = User::factory()->create(['hourly_cost' => 20.0]);

        $timesheet = ProjectTimesheet::query()->create([
            'task_id' => $task->id,
            'user_id' => $employee->id,
            'date' => '2026-06-03',
            'unit_amount' => 2.5,
            'name' => 'Wiring',
        ]);

        $timesheet->update(['unit_amount' => 4.0]);

        $lines = AnalyticLine::query()->where('source_ref', $this->refOf($timesheet))->get();
        $this->assertCount(1, $lines);                     // upsert, not insert
        $this->assertSame(-80.0, $lines->first()?->amount); // 4 × 20
    }

    public function test_deleting_a_timesheet_removes_its_cost_line(): void
    {
        $task = $this->taskOnCostedProject();
        $employee = User::factory()->create(['hourly_cost' => 20.0]);

        $timesheet = ProjectTimesheet::query()->create([
            'task_id' => $task->id,
            'user_id' => $employee->id,
            'date' => '2026-06-03',
            'unit_amount' => 2.5,
            'name' => 'Wiring',
        ]);
        $ref = $this->refOf($timesheet);

        $timesheet->delete();

        $this->assertSame(0, AnalyticLine::query()->where('source_ref', $ref)->count());
    }

    public function test_employee_without_a_rate_falls_back_to_the_configured_default(): void
    {
        config(['project.default_hourly_cost' => 15.0]);

        $task = $this->taskOnCostedProject();
        $employee = User::factory()->create(['hourly_cost' => null]);

        $timesheet = ProjectTimesheet::query()->create([
            'task_id' => $task->id,
            'user_id' => $employee->id,
            'date' => '2026-06-03',
            'unit_amount' => 2.0,
            'name' => 'Triage',
        ]);

        $line = AnalyticLine::query()->where('source_ref', $this->refOf($timesheet))->first();
        $this->assertSame(-30.0, $line?->amount);          // 2h × 15 default
    }

    public function test_a_project_without_an_analytic_account_posts_nothing(): void
    {
        // Turn auto-provisioning off so the project has no analytic account.
        config(['project.analytic.auto_create' => false]);

        $project = Project::query()->create(['name' => 'Uncosted']);
        $this->assertNull($project->analytic_account_id);

        $stage = ProjectStage::query()->create(['project_id' => $project->id, 'name' => 'Do', 'sequence' => 0]);
        $task = ProjectTask::query()->create(['project_id' => $project->id, 'stage_id' => $stage->id, 'title' => 'T']);
        $employee = User::factory()->create(['hourly_cost' => 20.0]);

        ProjectTimesheet::query()->create([
            'task_id' => $task->id,
            'user_id' => $employee->id,
            'date' => '2026-06-03',
            'unit_amount' => 5.0,
            'name' => 'Work',
        ]);

        $this->assertSame(0, AnalyticLine::query()->count());
    }

    public function test_disabling_analytic_costing_skips_posting(): void
    {
        config(['project.analytic.enabled' => false]);

        $task = $this->taskOnCostedProject();
        $employee = User::factory()->create(['hourly_cost' => 20.0]);

        ProjectTimesheet::query()->create([
            'task_id' => $task->id,
            'user_id' => $employee->id,
            'date' => '2026-06-03',
            'unit_amount' => 5.0,
            'name' => 'Work',
        ]);

        $this->assertSame(0, AnalyticLine::query()->count());
    }

    public function test_effective_and_remaining_hours_sum_timesheets(): void
    {
        $task = $this->taskOnCostedProject(plannedHours: 10.0);
        $employee = User::factory()->create(['hourly_cost' => 20.0]);

        foreach ([3.0, 2.5] as $hours) {
            ProjectTimesheet::query()->create([
                'task_id' => $task->id,
                'user_id' => $employee->id,
                'date' => '2026-06-03',
                'unit_amount' => $hours,
                'name' => 'Work',
            ]);
        }

        $fresh = $task->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(5.5, $fresh->effective_hours);   // SUM-query fallback path
        $this->assertSame(4.5, $fresh->remaining_hours);   // 10 − 5.5
    }

    public function test_effective_hours_uses_the_preloaded_withsum_aggregate(): void
    {
        $task = $this->taskOnCostedProject(plannedHours: 8.0);
        $employee = User::factory()->create(['hourly_cost' => 20.0]);

        ProjectTimesheet::query()->create([
            'task_id' => $task->id,
            'user_id' => $employee->id,
            'date' => '2026-06-03',
            'unit_amount' => 6.0,
            'name' => 'Work',
        ]);

        // The board loads tasks with withSum(); the accessor must read that
        // aggregate instead of firing its own query.
        $loaded = ProjectTask::query()
            ->withSum('timesheets', 'unit_amount')
            ->findOrFail($task->id);

        $this->assertSame(6.0, $loaded->effective_hours);
        $this->assertSame(2.0, $loaded->remaining_hours);
    }
}
