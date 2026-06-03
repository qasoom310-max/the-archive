<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Project\Livewire\ProjectBoard;
use Modules\Project\Livewire\ProjectHome;
use Modules\Project\Livewire\Projects;
use Modules\Project\Livewire\Tasks;

Route::middleware('auth')->group(function (): void {
    // App landing — project cards, each linking to its Kanban board.
    Route::get('/app/project', ProjectHome::class)->name('project.home');

    // Sidebar resource entries for the registered ir_models
    // (project.project / project.task) — engine list/form views.
    Route::get('/app/project/project', Projects::class)->name('project.project.index');
    Route::get('/app/project/task', Tasks::class)->name('project.task.index');

    // The Kanban board for a single project. {project} is the id.
    Route::get('/app/project/{project}/board', ProjectBoard::class)
        ->whereNumber('project')->name('project.board');
});
