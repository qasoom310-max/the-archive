<?php

declare(strict_types=1);

namespace Modules\Project\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Project\Services\TimesheetCostPoster;
use Throwable;

/**
 * One line of logged work against a task. `unit_amount` is the hours spent
 * (e.g. 2.50). Saving (or deleting) a line keeps its analytic cost line in
 * sync via {@see TimesheetCostPoster} — see booted().
 *
 * @property int $id
 * @property int $task_id
 * @property int|null $user_id
 * @property Carbon $date
 * @property float $unit_amount   Hours spent.
 * @property string $name         Description of the work done.
 */
final class ProjectTimesheet extends Model
{
    protected $table = 'project_timesheets';

    /** @var list<string> */
    protected $fillable = ['task_id', 'user_id', 'date', 'unit_amount', 'name'];

    /** @var array<string, mixed> */
    protected $attributes = ['unit_amount' => 0, 'name' => ''];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'task_id' => 'integer',
            'user_id' => 'integer',
            'date' => 'date',
            'unit_amount' => 'float',
        ];
    }

    protected static function booted(): void
    {
        // The "hook": every persisted timesheet (re)posts its statistical
        // cost line to the project's analytic account. Idempotent per
        // timesheet (keyed by source_ref). Errors are swallowed + logged to
        // the task's Chatter — a costing hiccup must never block time entry
        // (same convention as the POS→WhatsApp / POS→Accounting listeners).
        static::saved(function (ProjectTimesheet $timesheet): void {
            try {
                app(TimesheetCostPoster::class)->sync($timesheet);
            } catch (Throwable $e) {
                $task = $timesheet->task;
                if ($task instanceof ProjectTask) {
                    $task->logChange('Analytic cost sync failed: ' . $e->getMessage());
                }
            }
        });

        static::deleted(function (ProjectTimesheet $timesheet): void {
            try {
                app(TimesheetCostPoster::class)->remove($timesheet);
            } catch (Throwable) {
                // Best-effort cleanup; an orphaned analytic line is harmless.
            }
        });
    }

    /**
     * @return BelongsTo<ProjectTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'task_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
