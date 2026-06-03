<?php

declare(strict_types=1);

return [
    // Fallback labour rate (per hour) used when an employee has no
    // per-user `users.hourly_cost` set. Kept here (outside the project
    // `config/` dir) so the larastan `noEnvCallsOutsideOfConfig` rule is
    // satisfied — no env() calls in this file.
    'default_hourly_cost' => 0.0,

    'analytic' => [
        // Master switch for the timesheet → analytic cost posting. Turn
        // off to log hours without booking any managerial cost.
        'enabled' => true,

        // Auto-provision an analytic account for every new project so
        // timesheet costs always have somewhere to land (Odoo behaviour).
        'auto_create' => true,
    ],
];
