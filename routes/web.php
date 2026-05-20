<?php

declare(strict_types=1);

use App\Livewire\Auth\Login;
use App\Livewire\Pages\Dashboard;
use App\Livewire\Pages\ModuleHome;
use App\Livewire\Pages\Playground;
use App\Livewire\Pages\SettingsPage;
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

Route::middleware('auth')->group(function (): void {
    Route::get('/', Dashboard::class)->name('dashboard');
    Route::get('/playground', Playground::class)->name('playground');

    // Explicit before the /app/{module} wildcard so it wins.
    Route::get('/app/settings', SettingsPage::class)->name('settings');

    Route::get('/app/{module}', ModuleHome::class)->name('module.home');
});
