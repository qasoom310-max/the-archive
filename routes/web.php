<?php

declare(strict_types=1);

use App\Http\Controllers\FormFileUploadController;
use App\Http\Controllers\FormImageUploadController;
use App\Http\Controllers\PayslipPdfController;
use App\Http\Controllers\ProfileEmailVerificationController;
use App\Http\Controllers\ChooseWorkspaceController;
use App\Http\Controllers\SwitchWorkspaceController;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\ResetPassword;
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

    // Locked out: ask for a reset link, then set the new password from it.
    // Guests always run against MAIN (SetActiveWorkspace short-circuits an
    // unauthenticated request), which is the database login authenticates
    // against - so the password rewritten here is the one that signs in.
    Route::get('/forgot-password', ForgotPassword::class)->name('password.request');
    Route::get('/reset-password/{token}', ResetPassword::class)->name('password.reset');
});

Route::post('/logout', function (): RedirectResponse {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout');

// Idle sign-out: posted by the layout's inactivity timer after 15 minutes
// without a key press, click or mouse movement on a laptop/desktop (phones
// are exempt - see the idle script in layouts/app.blade.php). The request
// attribute tells the Logout listener to audit it as an idle sign-out rather
// than a deliberate one.
Route::post('/logout/idle', function (): RedirectResponse {
    request()->attributes->set('idle_logout', true);
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('login')
        ->with('status', __('You were signed out after 15 minutes of inactivity.'));
})->middleware('auth')->name('logout.idle');

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

    // Admin-only ad calendar: last year's sales as a heatmap, the selling
    // windows ahead, and when the ads must be live. Component gates on admin.
    Route::get('/calendar', \App\Livewire\Pages\AdCalendar::class)->name('calendar');

    // Saved logins for this database. Admin-gated in the component, with
    // owner-only entries excluded by the query for everyone else.
    Route::get('/logins', \App\Livewire\Pages\Vault::class)->name('vault');

    // Admin-only published fares — the single source the website reads over
    // the pricing API. Component gates on admin.
    Route::get('/fares', \App\Livewire\Pages\PricingManager::class)->name('pricing');

    // Admin-only prices agreed with companies. Never published to the
    // website; read by the staff assistant. Component gates on admin.
    Route::get('/corporate-rates', \App\Livewire\Pages\CorporateRates::class)->name('corporate-rates');

    // Admin-only database-backup download (the list + restore UI lives in the
    // Activity Log page). Before the /app/{module} wildcard so it isn't shadowed.
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
    // The same uploader, storing on this server instead (config erp.video_storage
    // = local). Chunked, so the throttle is per chunk: 1,000 a minute is ~2 GB.
    Route::post('/app/video/upload-chunk', \App\Http\Controllers\VideoUploadController::class)
        ->middleware('throttle:1000,1')->name('video.upload-chunk');

    // Per-app feature toggles (the app's own "Settings" tab). Two-segment and
    // more specific than the /app/{module} home below; no module defines an
    // `/app/{module}/settings` route, so this owns the path.
    Route::get('/app/{module}/settings', AppFeatureSettings::class)
        ->where('module', '[A-Za-z0-9_-]+')
        ->name('app.feature-settings');

    // Downloads of any engine list export (Copy needs no route — it lifts
    // the rendered table client-side). {modelKey} is a dotted ir_model id
    // (e.g. "rental.vehicle"), so the constraint allows dots.
    Route::get('/app/export/{modelKey}/csv', [\App\Http\Controllers\ListExportController::class, 'csv'])
        ->where('modelKey', '[A-Za-z0-9_.]+')->name('list.export.csv');
    Route::get('/app/export/{modelKey}/excel', [\App\Http\Controllers\ListExportController::class, 'excel'])
        ->where('modelKey', '[A-Za-z0-9_.]+')->name('list.export.excel');
    Route::get('/app/export/{modelKey}/pdf', [\App\Http\Controllers\ListExportController::class, 'pdf'])
        ->where('modelKey', '[A-Za-z0-9_.]+')->name('list.export.pdf');
    Route::get('/app/export/{modelKey}/print', [\App\Http\Controllers\ListExportController::class, 'print'])
        ->where('modelKey', '[A-Za-z0-9_.]+')->name('list.export.print');

    Route::get('/app/{module}', ModuleHome::class)->name('module.home');
});

/*
 * Published fares, read by the Wanaan website server-to-server.
 *
 * Outside the `auth` group on purpose — WordPress has no session here. Its
 * only credential is the path-bound HMAC signature, verified in the
 * controller against that workspace's own shared secret, so a signature
 * minted for one database cannot read another's prices.
 */
Route::get('/api/v1/workspaces/{ws}/pricing', \App\Http\Controllers\PricingApiController::class)
    ->where('ws', '[0-9]+')
    ->middleware('throttle:60,1')
    ->name('api.pricing');
