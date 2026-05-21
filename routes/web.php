<?php

declare(strict_types=1);

use App\Http\Controllers\ProfileEmailVerificationController;
use App\Livewire\Auth\Login;
use App\Livewire\Pages\Dashboard;
use App\Livewire\Pages\ModuleHome;
use App\Livewire\Pages\Playground;
use App\Livewire\Pages\SettingsPage;
use App\Livewire\ProfilePage;
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

    // Self-service profile settings — accessible from the user dropdown
    // in the topbar. Role display is read-only here; the admin user
    // resource is the entry point for editing other users' roles.
    Route::get('/profile', ProfilePage::class)->name('profile');

    // Explicit before the /app/{module} wildcard so it wins.
    Route::get('/app/settings', SettingsPage::class)->name('settings');

    Route::get('/app/{module}', ModuleHome::class)->name('module.home');
});
