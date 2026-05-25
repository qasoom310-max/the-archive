<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Accounting\Livewire\AccountForm;
use Modules\Accounting\Livewire\Accounts;
use Modules\Accounting\Livewire\JournalEntries;
use Modules\Accounting\Livewire\JournalEntryForm;

/*
 * Every `DefinesIrModel` in this module needs an index route here — the
 * engine reads `ir_model` to render the sidebar, but it does NOT auto-
 * mount routes. Without these the sidebar entries 404.
 * (memory: module-sidebar-and-engine-contract)
 */
Route::middleware('auth')->group(function (): void {
    // Module home — points at the Chart of Accounts for now.
    Route::redirect('/app/accounting', '/app/accounting/account');

    // Chart of Accounts
    Route::get('/app/accounting/account', Accounts::class)
        ->name('accounting.account.index');
    Route::get('/app/accounting/account/new', AccountForm::class)
        ->name('accounting.account.create');
    Route::get('/app/accounting/account/{id}', AccountForm::class)
        ->whereNumber('id')
        ->name('accounting.account.edit');

    // Journal Entries
    Route::get('/app/accounting/journal-entry', JournalEntries::class)
        ->name('accounting.journal_entry.index');
    Route::get('/app/accounting/journal-entry/new', JournalEntryForm::class)
        ->name('accounting.journal_entry.create');
    Route::get('/app/accounting/journal-entry/{id}', JournalEntryForm::class)
        ->whereNumber('id')
        ->name('accounting.journal_entry.edit');
});
