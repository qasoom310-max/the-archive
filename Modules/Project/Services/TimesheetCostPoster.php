<?php

declare(strict_types=1);

namespace Modules\Project\Services;

use App\Models\User;
use Modules\Project\Models\AnalyticLine;
use Modules\Project\Models\ProjectTimesheet;

/**
 * Posts (and keeps in sync) the "statistical" analytic cost line for a
 * timesheet: cost = hours × the employee's hourly rate, stored as a
 * NEGATIVE amount on the project's analytic account.
 *
 * This is deliberately NOT a financial journal entry. Labour cost recorded
 * here is managerial / analytic only (the Odoo "analytic line" concept) —
 * booking it into the GL would double-count payroll. The single entry point
 * the rest of the module touches is {@see sync()} / {@see remove()}, both
 * idempotent per timesheet via the line's `source_ref`.
 */
final class TimesheetCostPoster
{
    /**
     * Upsert the analytic cost line for one timesheet. Safe to call on every
     * save — exactly one line exists per timesheet (keyed by source_ref).
     */
    public function sync(ProjectTimesheet $timesheet): void
    {
        if (! (bool) config('project.analytic.enabled', true)) {
            return;
        }

        $timesheet->loadMissing('task.project', 'user');

        $task = $timesheet->task;
        $project = $task?->project;
        $analyticId = $project?->analytic_account_id;

        if ($analyticId === null) {
            // No analytic account on the project → nothing to cost. Clear any
            // stale line (e.g. the project was later unlinked).
            $this->remove($timesheet);

            return;
        }

        $rate = $this->hourlyRate($timesheet->user);
        $hours = round($timesheet->unit_amount, 2);
        $cost = -1.0 * round($hours * $rate, 2); // costs are stored negative

        AnalyticLine::query()->updateOrCreate(
            ['source_ref' => $this->ref($timesheet)],
            [
                'analytic_account_id' => $analyticId,
                'date' => $timesheet->date,
                'name' => $timesheet->name !== '' ? $timesheet->name : __('Timesheet'),
                'unit_amount' => $hours,
                'amount' => $cost,
                'user_id' => $timesheet->user_id,
            ],
        );
    }

    /**
     * Drop the analytic line for a timesheet (on delete, or when its project
     * loses its analytic link). Best-effort — a missing line is a no-op.
     */
    public function remove(ProjectTimesheet $timesheet): void
    {
        AnalyticLine::query()
            ->where('source_ref', $this->ref($timesheet))
            ->delete();
    }

    /**
     * Per-user hourly cost, falling back to the configured default when the
     * employee has no rate (or a zero/negative one) on file.
     */
    private function hourlyRate(?User $user): float
    {
        $cost = $user?->hourly_cost;

        if ($cost !== null && (float) $cost > 0.0) {
            return (float) $cost;
        }

        return (float) config('project.default_hourly_cost', 0.0);
    }

    private function ref(ProjectTimesheet $timesheet): string
    {
        return 'project.timesheet:' . $timesheet->getKey();
    }
}
