<?php

declare(strict_types=1);

use App\Http\Controllers\Api\App\AuthController;
use App\Http\Controllers\Api\App\DeviceController;
use App\Http\Middleware\AppLocale;
use App\Http\Middleware\AppWorkspace;
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

            Route::post('/devices', [DeviceController::class, 'store']);
            Route::delete('/devices', [DeviceController::class, 'destroy']);

            Route::get('/trips', [TripController::class, 'index']);
            Route::get('/trips/{leg}', [TripController::class, 'show'])->whereNumber('leg');
            Route::get('/trips/{leg}/crew-options', [TripController::class, 'crewOptions'])->whereNumber('leg');
            Route::post('/trips/{leg}/assign', [TripController::class, 'assign'])->whereNumber('leg');
        });
    });
});
