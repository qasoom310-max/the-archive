<?php

declare(strict_types=1);

use App\Http\Controllers\Api\App\AuthController;
use App\Http\Controllers\Api\App\DeviceController;
use App\Http\Controllers\Api\Office\OfficeLimousineController;
use App\Http\Controllers\Api\Office\OfficeReadController;
use App\Http\Controllers\Api\Office\OfficeRentalController;
use App\Http\Middleware\AppLocale;
use App\Http\Middleware\AppWorkspace;
use App\Http\Middleware\OfficeApi;
use Illuminate\Support\Facades\Route;
use Modules\Limousine\Http\Controllers\App\TripController;

/*
| The staff app (Android / iPhone): /api/app/…
|
| Signed in with a token (Sanctum, stored on Main). Every data route then runs
| inside the person's own business database (AppWorkspace), under the same
| permissions as the website — AccessControl and the trip-details lock apply
| exactly as they do on screen. The app sends `Accept: application/json`, so
| failures come back as JSON, never a redirect to the sign-in page.
*/
Route::prefix('app')->middleware(AppLocale::class)->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1');

    Route::middleware('auth:sanctum')->group(function (): void {
        // On Main, where the token lives.
        Route::post('/logout', [AuthController::class, 'logout']);

        Route::middleware([AppWorkspace::class, 'throttle:240,1'])->group(function (): void {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/account/deletion-request', [AuthController::class, 'requestDeletion'])->middleware('throttle:5,60');

            Route::post('/devices', [DeviceController::class, 'store']);
            Route::delete('/devices', [DeviceController::class, 'destroy']);

            Route::get('/trips', [TripController::class, 'index']);
            Route::get('/trips/{leg}', [TripController::class, 'show'])->whereNumber('leg');
            Route::get('/trips/{leg}/crew-options', [TripController::class, 'crewOptions'])->whereNumber('leg');
            Route::post('/trips/{leg}/assign', [TripController::class, 'assign'])->whereNumber('leg');
        });
    });
});

/*
| The AI office (a separate app): /api/office/v1/…
|
| READ ONLY. An office key (php artisan office:key …), never a staff login; one
| business database per request, named in X-Workspace and listed on the key;
| GET only, and that database is locked to query_only while the request runs
| (OfficeApi). The rate limit runs first, while the cache is still Main's.
*/
Route::prefix('office/v1')->middleware(['throttle:120,1', OfficeApi::class])->group(function (): void {
    Route::get('/workspace', [OfficeReadController::class, 'workspace']);
    Route::get('/staff', [OfficeReadController::class, 'staff']);
    Route::get('/activity', [OfficeReadController::class, 'activity']);
    Route::get('/accounting/journal-entries', [OfficeReadController::class, 'journalEntries']);
    Route::get('/limousine/trips', [OfficeLimousineController::class, 'trips']);
    Route::get('/limousine/invoices', [OfficeLimousineController::class, 'invoices']);
    Route::get('/limousine/expenses', [OfficeLimousineController::class, 'expenses']);
    Route::get('/rental/orders', [OfficeRentalController::class, 'orders']);
    Route::get('/rental/fleet', [OfficeRentalController::class, 'fleet']);
});
