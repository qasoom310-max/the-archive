<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Limousine\Http\Controllers\CouponVoucherController;
use Modules\Limousine\Http\Controllers\LimoQueueExportController;
use Modules\Limousine\Http\Controllers\LimoReceiptController;
use Modules\Limousine\Http\Controllers\LimoReportExportController;
use Modules\Limousine\Http\Controllers\ServiceOrderController;
use Modules\Limousine\Http\Controllers\ServiceOrderSignController;
use Modules\Limousine\Livewire\Bookings;
use Modules\Limousine\Livewire\BookingForm;
use Modules\Limousine\Livewire\Coupons;
use Modules\Limousine\Livewire\CustomerForm;
use Modules\Rental\Livewire\CustomerForm as RentalCustomerForm;
use Modules\Limousine\Livewire\DriverForm;
use Modules\Limousine\Livewire\Drivers;
use Modules\Limousine\Livewire\Customers;
use Modules\Limousine\Livewire\ExpenseForm;
use Modules\Limousine\Livewire\Expenses;
use Modules\Limousine\Livewire\InvoiceForm;
use Modules\Limousine\Livewire\Invoices;
use Modules\Limousine\Livewire\LimoHome;
use Modules\Limousine\Livewire\LocationForm;
use Modules\Limousine\Livewire\Locations;
use Modules\Limousine\Livewire\QuotationForm;
use Modules\Limousine\Livewire\Quotations;
use Modules\Limousine\Livewire\ReceiptForm;
use Modules\Limousine\Livewire\Receipts;
use Modules\Limousine\Livewire\Reports;

/*
 * PUBLIC signing links. A customer has no account, so these sit outside `auth`
 * — the URL's own signature is the credential. `signed` middleware rejects any
 * edit to the leg id or the expiry, so one customer's link can never be walked
 * to another customer's trip.
 */
Route::middleware('signed')->group(function (): void {
    Route::get('/service-order/{leg}/sign', [ServiceOrderSignController::class, 'show'])
        ->whereNumber('leg')->name('limousine.service_order.sign');
    Route::post('/service-order/{leg}/sign', [ServiceOrderSignController::class, 'store'])
        ->whereNumber('leg')->name('limousine.service_order.submit');
    Route::get('/service-order/{leg}/sign/pdf', [ServiceOrderSignController::class, 'pdf'])
        ->whereNumber('leg')->name('limousine.service_order.customer_pdf');
});

Route::middleware('auth')->group(function (): void {
    // App landing — bookings dashboard.
    Route::get('/app/limousine', LimoHome::class)->name('limousine.home');

    // Service Order — the per-trip sheet. Staff download; the customer gets a
    // signing link by email instead.
    Route::get('/app/limousine/service-order/{leg}', ServiceOrderController::class)
        ->whereNumber('leg')->name('limousine.service_order.pdf');

    // Masters.
    Route::get('/app/limousine/customer', Customers::class)->name('limousine.customer.index');
    // ONE customer page for both apps — the same person hires a car and books a
    // trip, so they get one record with one summary rather than half of each.
    // The Limousine permission key travels with the route, so a user granted
    // Limousine customers and not Rent A Car ones keeps exactly their access.
    Route::get('/app/limousine/customer/new', RentalCustomerForm::class)
        ->defaults('modelKey', 'limousine.customer')
        ->name('limousine.customer.create');
    Route::get('/app/limousine/customer/{id}', RentalCustomerForm::class)
        ->whereNumber('id')
        ->defaults('modelKey', 'limousine.customer')
        ->name('limousine.customer.edit');

    // Drivers — the same list Rent A Car uses; see LimoDriver.
    Route::get('/app/limousine/driver', Drivers::class)->name('limousine.driver.index');
    Route::get('/app/limousine/driver/new', DriverForm::class)->name('limousine.driver.create');
    Route::get('/app/limousine/driver/{id}', DriverForm::class)->whereNumber('id')->name('limousine.driver.edit');

    Route::get('/app/limousine/location', Locations::class)->name('limousine.location.index');
    Route::get('/app/limousine/location/new', LocationForm::class)->name('limousine.location.create');
    Route::get('/app/limousine/location/{id}', LocationForm::class)->whereNumber('id')->name('limousine.location.edit');

    // Bookings — trips.
    // Queue downloads. Registered BEFORE the `{id}` route, or "export" would be
    // swallowed as a booking id — the numeric constraint already prevents that,
    // but ordering keeps it true if the constraint is ever relaxed.
    Route::get('/app/limousine/booking/export/csv', [LimoQueueExportController::class, 'csv'])->name('limousine.queue.csv');
    Route::get('/app/limousine/booking/export/excel', [LimoQueueExportController::class, 'excel'])->name('limousine.queue.excel');
    Route::get('/app/limousine/booking/export/pdf', [LimoQueueExportController::class, 'pdf'])->name('limousine.queue.pdf');
    Route::get('/app/limousine/booking/export/print', [LimoQueueExportController::class, 'print'])->name('limousine.queue.print');

    Route::get('/app/limousine/booking', Bookings::class)->name('limousine.booking.index');
    Route::get('/app/limousine/booking/new', BookingForm::class)->name('limousine.booking.create');
    Route::get('/app/limousine/booking/{id}', BookingForm::class)->whereNumber('id')->name('limousine.booking.edit');

    // Refund coupons — credit from cancelled trips, and how much of each is left.
    Route::get('/app/limousine/coupon', Coupons::class)->name('limousine.coupon.index');
    // The customer's copy, handed over or emailed by the office.
    Route::get('/app/limousine/coupon/{coupon}/pdf', CouponVoucherController::class)
        ->whereNumber('coupon')->name('limousine.coupon.pdf');

    // Quotations.
    Route::get('/app/limousine/quotation', Quotations::class)->name('limousine.quotation.index');
    Route::get('/app/limousine/quotation/new', QuotationForm::class)->name('limousine.quotation.create');
    Route::get('/app/limousine/quotation/{id}', QuotationForm::class)->whereNumber('id')->name('limousine.quotation.edit');

    // Invoices.
    Route::get('/app/limousine/invoice', Invoices::class)->name('limousine.invoice.index');
    Route::get('/app/limousine/invoice/new', InvoiceForm::class)->name('limousine.invoice.create');
    Route::get('/app/limousine/invoice/{id}', InvoiceForm::class)->whereNumber('id')->name('limousine.invoice.edit');

    // Receipts.
    Route::get('/app/limousine/receipt', Receipts::class)->name('limousine.receipt.index');
    Route::get('/app/limousine/receipt/new', ReceiptForm::class)->name('limousine.receipt.create');
    // Before the {id} form route, so "download" is never read as a receipt id.
    Route::get('/app/limousine/receipt/{receipt}/download', LimoReceiptController::class)
        ->whereNumber('receipt')->name('limousine.receipt.download');
    Route::get('/app/limousine/receipt/{id}', ReceiptForm::class)->whereNumber('id')->name('limousine.receipt.edit');

    // Expenses.
    Route::get('/app/limousine/expense', Expenses::class)->name('limousine.expense.index');
    Route::get('/app/limousine/expense/new', ExpenseForm::class)->name('limousine.expense.create');
    Route::get('/app/limousine/expense/{id}', ExpenseForm::class)->whereNumber('id')->name('limousine.expense.edit');

    // Reports (+ CSV export declared before the page so it isn't shadowed).
    Route::get('/app/limousine/reports/bookings/export', LimoReportExportController::class)->name('limousine.reports.export');
    Route::get('/app/limousine/reports', Reports::class)->name('limousine.reports');
});
