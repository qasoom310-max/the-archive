<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Rental\Livewire\BranchForm;
use Modules\Rental\Livewire\Branches;
use Modules\Rental\Livewire\CustomerForm;
use Modules\Rental\Livewire\Customers;
use Modules\Rental\Livewire\DriverForm;
use Modules\Rental\Livewire\Drivers;
use Modules\Rental\Livewire\RentalHome;
use Modules\Rental\Livewire\VehicleForm;
use Modules\Rental\Livewire\Vehicles;

Route::middleware('auth')->group(function (): void {
    // App landing — the operations dashboard (fleet availability KPIs).
    Route::get('/app/rental', RentalHome::class)->name('rental.home');

    // Masters. `new` is declared before the numeric {id} so it isn't
    // captured as an id (same convention as the other modules).
    Route::get('/app/rental/branch', Branches::class)->name('rental.branch.index');
    Route::get('/app/rental/branch/new', BranchForm::class)->name('rental.branch.create');
    Route::get('/app/rental/branch/{id}', BranchForm::class)->whereNumber('id')->name('rental.branch.edit');

    Route::get('/app/rental/customer', Customers::class)->name('rental.customer.index');
    Route::get('/app/rental/customer/new', CustomerForm::class)->name('rental.customer.create');
    Route::get('/app/rental/customer/{id}', CustomerForm::class)->whereNumber('id')->name('rental.customer.edit');

    Route::get('/app/rental/vehicle', Vehicles::class)->name('rental.vehicle.index');
    Route::get('/app/rental/vehicle/new', VehicleForm::class)->name('rental.vehicle.create');
    Route::get('/app/rental/vehicle/{id}', VehicleForm::class)->whereNumber('id')->name('rental.vehicle.edit');

    Route::get('/app/rental/driver', Drivers::class)->name('rental.driver.index');
    Route::get('/app/rental/driver/new', DriverForm::class)->name('rental.driver.create');
    Route::get('/app/rental/driver/{id}', DriverForm::class)->whereNumber('id')->name('rental.driver.edit');
});
