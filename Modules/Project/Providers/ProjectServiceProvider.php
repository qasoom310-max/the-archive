<?php

declare(strict_types=1);

namespace Modules\Project\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Project\Services\TimesheetCostPoster;

/**
 * Project module provider. Loaded by the core ModuleServiceProvider only
 * while the module is installed; routes/views are auto-discovered by the
 * engine.
 *
 * Timesheet → analytic costing is wired through the ProjectTimesheet
 * model's saved/deleted hooks (kept inline + idempotent there) rather than
 * a cross-module event, so no Event::listen() is needed in boot().
 */
final class ProjectServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/project.php', 'project');

        // Stateless aside from cached config — a singleton keeps allocations
        // down when a batch of timesheets is saved in one request.
        $this->app->singleton(TimesheetCostPoster::class);
    }

    public function boot(): void
    {
        // No event wiring: the costing hook lives on the ProjectTimesheet
        // model. This method is intentionally empty but kept for parity
        // with the other module providers and future event automations.
    }
}
