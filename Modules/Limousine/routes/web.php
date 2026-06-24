<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Limousine\Http\Controllers\LimoReportExportController;
use Modules\Limousine\Livewire\Bookings;
use Modules\Limousine\Livewire\BookingForm;
use Modules\Limousine\Livewire\CustomerForm;
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

Route::middleware('auth')->group(function (): void {
    // App landing — bookings dashboard.
    Route::get('/app/limousine', LimoHome::class)->name('limousine.home');

    // Masters.
    Route::get('/app/limousine/customer', Customers::class)->name('limousine.customer.index');
    Route::get('/app/limousine/customer/new', CustomerForm::class)->name('limousine.customer.create');
    Route::get('/app/limousine/customer/{id}', CustomerForm::class)->whereNumber('id')->name('limousine.customer.edit');

    Route::get('/app/limousine/location', Locations::class)->name('limousine.location.index');
    Route::get('/app/limousine/location/new', LocationForm::class)->name('limousine.location.create');
    Route::get('/app/limousine/location/{id}', LocationForm::class)->whereNumber('id')->name('limousine.location.edit');

    // Bookings — trips.
    Route::get('/app/limousine/booking', Bookings::class)->name('limousine.booking.index');
    Route::get('/app/limousine/booking/new', BookingForm::class)->name('limousine.booking.create');
    Route::get('/app/limousine/booking/{id}', BookingForm::class)->whereNumber('id')->name('limousine.booking.edit');

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
    Route::get('/app/limousine/receipt/{id}', ReceiptForm::class)->whereNumber('id')->name('limousine.receipt.edit');

    // Expenses.
    Route::get('/app/limousine/expense', Expenses::class)->name('limousine.expense.index');
    Route::get('/app/limousine/expense/new', ExpenseForm::class)->name('limousine.expense.create');
    Route::get('/app/limousine/expense/{id}', ExpenseForm::class)->whereNumber('id')->name('limousine.expense.edit');

    // Reports (+ CSV export declared before the page so it isn't shadowed).
    Route::get('/app/limousine/reports/bookings/export', LimoReportExportController::class)->name('limousine.reports.export');
    Route::get('/app/limousine/reports', Reports::class)->name('limousine.reports');
});
