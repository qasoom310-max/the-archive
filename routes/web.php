<?php

declare(strict_types=1);

use App\Http\Controllers\FormFileUploadController;
use App\Http\Controllers\FormImageUploadController;
use App\Http\Controllers\ProfileEmailVerificationController;
use App\Http\Controllers\SwitchWorkspaceController;
use App\Livewire\Auth\Login;
use App\Livewire\Pages\ActivityLog;
use App\Livewire\Pages\DailySummary;
use App\Livewire\Pages\Dashboard;
use App\Livewire\Pages\ModuleHome;
use App\Livewire\Pages\MonthlyProfit;
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

    // Admin-only audit trail (topbar activity icon). Component gates on admin.
    Route::get('/activity', ActivityLog::class)->name('activity');

    // Explicit before the /app/{module} wildcard so it wins.
    Route::get('/app/settings', SettingsPage::class)->name('settings');

    Route::get('/app/{module}', ModuleHome::class)->name('module.home');
});
