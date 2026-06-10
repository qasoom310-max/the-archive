<?php

return [
    // Workspace wiring registers BEFORE the others so the landlord/tenant
    // connections + session/queue pinning exist before anything resolves a DB.
    App\Providers\WorkspaceServiceProvider::class,
    App\Providers\AppServiceProvider::class,
    App\Providers\ModuleServiceProvider::class,
];
