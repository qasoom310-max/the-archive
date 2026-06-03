<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Auth\Group;
use App\Models\Auth\ModelAccess;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Modules\Project\Models\Project;
use Modules\Project\Models\ProjectStage;
use Modules\Project\Models\ProjectTask;
use Modules\Project\Models\ProjectTimesheet;

/**
 * The "Project / User" group + ACLs, plus a populated demo project so the
 * Kanban board shows something the moment the module is installed. No-ops
 * until the module's tables exist (safe to leave out of / in any chain);
 * demo data is created once (skipped if any project already exists), while
 * the group + ACL rules are re-asserted on every run.
 *
 * Lives at the project-root `database/seeders/` under `Database\Seeders`
 * (PSR-4 maps only that path — see memory `module-seeders-live-at-project-root`).
 */
final class ProjectSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('projects')) {
            return;
        }

        $this->ensureGroupAndAccess();

        if (Project::query()->exists()) {
            return;
        }

        $this->seedDemoProject();
    }

    private function ensureGroupAndAccess(): void
    {
        $group = Group::query()->updateOrCreate(
            ['code' => 'project_user'],
            ['name' => 'Project / User', 'description' => 'Manage projects, boards and timesheets'],
        );

        $sales = User::query()->where('email', 'sales@example.com')->first();
        if ($sales !== null) {
            $sales->groups()->syncWithoutDetaching([$group->id]);
        }

        // Project users get full operate (read/write/create) but no delete.
        $rules = [
            'project.project' => [true, true, true, false],
            'project.task' => [true, true, true, false],
        ];

        foreach ($rules as $model => [$read, $write, $create, $unlink]) {
            ModelAccess::query()->updateOrCreate(
                ['model' => $model, 'group_id' => $group->id],
                [
                    'name' => $model . ': Project user',
                    'perm_read' => $read,
                    'perm_write' => $write,
                    'perm_create' => $create,
                    'perm_unlink' => $unlink,
                ],
            );
        }
    }

    private function seedDemoProject(): void
    {
        // Give the demo assignees a labour rate so timesheet costs are
        // non-zero (the column exists once the module migration has run).
        $admin = User::query()->where('is_admin', true)->orderBy('id')->first();
        $sales = User::query()->where('email', 'sales@example.com')->first();

        if ($admin !== null && $admin->hourly_cost === null) {
            $admin->forceFill(['hourly_cost' => 25.0])->save();
        }
        if ($sales !== null && $sales->hourly_cost === null) {
            $sales->forceFill(['hourly_cost' => 18.0])->save();
        }

        // created() auto-provisions the analytic account.
        $project = Project::query()->create([
            'name' => 'Website Revamp',
            'description' => 'Redesign and rebuild the public marketing site.',
            'color' => '#714b67',
        ]);

        /** @var array<string, int> $stages */
        $stages = [];
        foreach (['Backlog', 'To Do', 'In Progress', 'Done'] as $i => $name) {
            $stages[$name] = (int) ProjectStage::query()->create([
                'project_id' => $project->id,
                'name' => $name,
                'sequence' => $i,
            ])->id;
        }

        // [title, stage, planned_hours, priority, kanban_state, blocked_reason, assignee]
        $tasks = [
            ['Design system audit', 'Backlog', 8.0, false, 'normal', null, null],
            ['Competitor research', 'Backlog', 5.0, false, 'normal', null, null],
            ['Home page redesign', 'To Do', 12.0, true, 'normal', null, $admin?->id],
            ['Migrate blog content', 'To Do', 6.0, false, 'normal', null, null],
            ['Checkout flow', 'In Progress', 16.0, true, 'blocked', 'Waiting on payment gateway keys', $sales?->id],
            ['Mobile navigation', 'In Progress', 4.0, false, 'normal', null, $admin?->id],
            ['Brand palette', 'Done', 3.0, false, 'done', null, null],
        ];

        $created = [];
        foreach ($tasks as $i => [$title, $stage, $planned, $priority, $state, $reason, $assignee]) {
            $created[$title] = ProjectTask::query()->create([
                'project_id' => $project->id,
                'stage_id' => $stages[$stage],
                'title' => $title,
                'user_id' => $assignee,
                'priority' => $priority,
                'kanban_state' => $state,
                'blocked_reason' => $reason,
                'planned_hours' => $planned,
                'sequence' => $i,
            ]);
        }

        // A couple of logged timesheets → these also post analytic cost lines.
        ProjectTimesheet::query()->create([
            'task_id' => $created['Home page redesign']->id,
            'user_id' => $admin?->id,
            'date' => now()->subDay()->toDateString(),
            'unit_amount' => 4.5,
            'name' => 'Wireframes + hero section',
        ]);
        ProjectTimesheet::query()->create([
            'task_id' => $created['Checkout flow']->id,
            'user_id' => $sales?->id,
            'date' => now()->toDateString(),
            'unit_amount' => 2.0,
            'name' => 'Gateway integration spike',
        ]);
    }
}
