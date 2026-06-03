<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Project\Livewire\ProjectBoard;
use Modules\Project\Livewire\ProjectForm;
use Modules\Project\Livewire\ProjectHome;
use Modules\Project\Livewire\Projects;
use Modules\Project\Livewire\TaskForm;
use Modules\Project\Livewire\Tasks;

Route::middleware('auth')->group(function (): void {
    // App landing — project cards, each linking to its Kanban board.
    Route::get('/app/project', ProjectHome::class)->name('project.home');

    // Projects: sidebar list + create/edit form. `new` is declared before
    // the numeric {id} so it isn't captured as an id.
    Route::get('/app/project/project', Projects::class)->name('project.project.index');
    Route::get('/app/project/project/new', ProjectForm::class)->name('project.project.create');
    Route::get('/app/project/project/{id}', ProjectForm::class)
        ->whereNumber('id')->name('project.project.edit');

    // Tasks: sidebar list + create/edit form.
    Route::get('/app/project/task', Tasks::class)->name('project.task.index');
    Route::get('/app/project/task/new', TaskForm::class)->name('project.task.create');
    Route::get('/app/project/task/{id}', TaskForm::class)
        ->whereNumber('id')->name('project.task.edit');

    // The Kanban board for a single project.
    Route::get('/app/project/{project}/board', ProjectBoard::class)
        ->whereNumber('project')->name('project.board');
});
