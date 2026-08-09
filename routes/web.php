<?php

declare(strict_types=1);

use App\Http\Controllers\FormFileUploadController;
use App\Http\Controllers\FormImageUploadController;
use App\Http\Controllers\PayslipPdfController;
use App\Http\Controllers\ProfileEmailVerificationController;
use App\Http\Controllers\ChooseWorkspaceController;
use App\Http\Controllers\SwitchWorkspaceController;
use App\Livewire\Auth\Login;
use App\Livewire\Pages\ActivityLog;
use App\Livewire\Pages\AppFeatureSettings;
use App\Livewire\Pages\DailySummary;
use App\Livewire\Pages\Dashboard;
use App\Livewire\Pages\EmployeeForm;
use App\Livewire\Pages\EmployeePayroll;
use App\Livewire\Pages\Employees;
use App\Livewire\Pages\ModuleHome;
use App\Livewire\Pages\MonthlyProfit;
use App\Livewire\Pages\Payroll;
use App\Livewire\Pages\Playground;
use App\Livewire\Pages\SettingsPage;
use App\Livewire\ProfilePage;
use App\Livewire\WorkspacesPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', Login::class)->name('login');
});

Route::post('/logout', function (): RedirectResponse {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout');

// Email-change verification — public + signed (Laravel's `signed` middleware
// enforces the URL signature). The signed token IS the auth; no login
// required, so a user who clicks the link from another device / after
// signing out still completes verification. The controller additionally
// re-hashes the user's CURRENT `new_email` against the URL's `hash` so
// stale links (after a second email-change request) are refused.
Route::get('/profile/email/verify/{id}/{hash}', ProfileEmailVerificationController::class)
    ->middleware('signed')
    ->name('profile.email.verify');

Route::middleware('auth')->group(function (): void {
    Route::get('/', Dashboard::class)->name('dashboard');
    Route::get('/playground', Playground::class)->name('playground');

    // Owner's daily cash flow: sales vs purchases (admin-gated in the component).
    Route::get('/reports/daily-summary', DailySummary::class)->name('reports.daily_summary');

    // Owner's monthly P&L: sales − COGS − expenses, with recurring-bill tracking.
    Route::get('/reports/profit', MonthlyProfit::class)->name('reports.profit');

    // HR / payroll (each page is admin-gated in its component). More specific
    // suffix routes are declared before the bare /{id} so they aren't swallowed.
    Route::get('/hr/employees', Employees::class)->name('hr.employees');
    Route::get('/hr/payroll', Payroll::class)->name('hr.payroll');
    Route::get('/hr/employee/new', EmployeeForm::class)->name('hr.employee.create');
    Route::get('/hr/employee/{id}/payslip', PayslipPdfController::class)->whereNumber('id')->name('hr.employee.payslip');
    Route::get('/hr/employee/{id}/payroll', EmployeePayroll::class)->whereNumber('id')->name('hr.employee.payroll');
    Route::get('/hr/employee/{id}', EmployeeForm::class)->whereNumber('id')->name('hr.employee.edit');

    // Direct synchronous image upload for FormView image fields. Bypasses
    // Livewire's two-phase async upload mechanism (unreliable on shared
    // hosts that gate multipart POSTs through mod_security). Throttle
    // bounds disk-fill DoS by a logged-in user — 30 uploads/min/user is
    // generous for legitimate use (one upload per product edit).
    Route::post('/form/upload-image', FormImageUploadController::class)
        ->middleware('throttle:30,1')
        ->name('form.upload-image');

    // Document sibling of the image upload — accepts PDF + images for
    // FormView `file` widget fields. Same throttle (disk-fill DoS guard).
    Route::post('/form/upload-file', FormFileUploadController::class)
        ->middleware('throttle:30,1')
        ->name('form.upload-file');

    // Self-service profile settings — accessible from the user dropdown
    // in the topbar. Role display is read-only here; the admin user
    // resource is the entry point for editing other users' roles.
    Route::get('/profile', ProfilePage::class)->name('profile');

    // Multi-database ("My database") manager — admin-only. List/create/delete
    // is the Livewire page; switching is a plain GET so the cookie rides the
    // redirect (see SwitchWorkspaceController).
    Route::get('/workspaces', WorkspacesPage::class)->name('workspaces');
    Route::get('/workspaces/switch/{workspace}', SwitchWorkspaceController::class)
        ->whereNumber('workspace')->name('workspaces.switch');

    // Post-login database chooser — every sign-in lands here so the user picks
    // which business database to enter (instead of a stale cookie deciding).
    // Plain GET controller so the workspace cookie rides the redirect.
    Route::get('/choose', [ChooseWorkspaceController::class, 'index'])->name('workspaces.choose');
    Route::get('/choose/{workspace}', [ChooseWorkspaceController::class, 'enter'])
        ->whereNumber('workspace')->name('workspaces.enter');

    // Admin-only audit trail (topbar activity icon). Component gates on admin.
    Route::get('/activity', ActivityLog::class)->name('activity');

    // Admin-only in-app database backups (daily snapshots + restore). The
    // download route sits before the /app/{module} wildcard so it isn't shadowed.
    Route::get('/app/backups', \App\Livewire\Pages\DatabaseBackups::class)->name('backups');
    Route::get('/app/backups/download', \App\Http\Controllers\BackupDownloadController::class)->name('backups.download');

    // Explicit before the /app/{module} wildcard so it wins.
    Route::get('/app/settings', SettingsPage::class)->name('settings');

    // Cloudflare Stream: admin settings tab + the browser uploader's two
    // endpoints (mint a one-time upload URL, then read the video's watch URL).
    Route::get('/app/settings/stream', \App\Livewire\Settings\StreamSettings::class)->name('stream.settings');
    Route::post('/app/stream/upload-url', [\App\Http\Controllers\StreamUploadController::class, 'uploadUrl'])
        ->middleware('throttle:60,1')->name('stream.upload-url');
    Route::get('/app/stream/{uid}/info', [\App\Http\Controllers\StreamUploadController::class, 'info'])
        ->where('uid', '[A-Za-z0-9]+')->middleware('throttle:120,1')->name('stream.info');

    // Per-app feature toggles (the app's own "Settings" tab). Two-segment and
    // more specific than the /app/{module} home below; no module defines an
    // `/app/{module}/settings` route, so this owns the path.
    Route::get('/app/{module}/settings', AppFeatureSettings::class)
        ->where('module', '[A-Za-z0-9_-]+')
        ->name('app.feature-settings');

    Route::get('/app/{module}', ModuleHome::class)->name('module.home');
});
