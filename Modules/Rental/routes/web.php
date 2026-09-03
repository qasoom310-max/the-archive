<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Rental\Http\Controllers\RentalAgreementPdfController;
use Modules\Rental\Http\Controllers\RentalAgreementPrintController;
use Modules\Rental\Http\Controllers\RentalCustomerImportController;
use Modules\Rental\Http\Controllers\RentalInvoiceExportController;
use Modules\Rental\Http\Controllers\RentalMaintenanceExportController;
use Modules\Rental\Http\Controllers\RentalOrderExportController;
use Modules\Rental\Http\Controllers\RentalOrderImportController;
use Modules\Rental\Http\Controllers\RentalQuotationExportController;
use Modules\Rental\Http\Controllers\RentalReceiptExportController;
use Modules\Rental\Http\Controllers\RentalReplacementExportController;
use Modules\Rental\Http\Controllers\RentalDriverImportController;
use Modules\Rental\Http\Controllers\RentalReportExportController;
use Modules\Rental\Http\Controllers\RentalSalesExportController;
use Modules\Rental\Http\Controllers\RentalSalesImportController;
use Modules\Rental\Http\Controllers\RentalVehicleImportController;
use Modules\Rental\Livewire\BranchForm;
use Modules\Rental\Livewire\Branches;
use Modules\Rental\Livewire\CustomerForm;
use Modules\Rental\Livewire\Customers;
use Modules\Rental\Livewire\DriverForm;
use Modules\Rental\Livewire\Drivers;
use Modules\Rental\Livewire\InvoiceForm;
use Modules\Rental\Livewire\Invoices;
use Modules\Rental\Livewire\MaintenanceForm;
use Modules\Rental\Livewire\MaintenanceRecords;
use Modules\Rental\Livewire\OrderForm;
use Modules\Rental\Livewire\Orders;
use Modules\Rental\Livewire\QuotationForm;
use Modules\Rental\Livewire\Quotations;
use Modules\Rental\Livewire\ReceiptForm;
use Modules\Rental\Livewire\Receipts;
use Modules\Rental\Livewire\RentalHome;
use Modules\Rental\Livewire\ReplacementForm;
use Modules\Rental\Livewire\Replacements;
use Modules\Rental\Livewire\Reports;
use Modules\Rental\Livewire\Sales;
use Modules\Rental\Livewire\VehicleForm;
use Modules\Rental\Livewire\Vehicles;

Route::middleware('auth')->group(function (): void {
    // App landing — the operations dashboard (fleet availability KPIs).
    Route::get('/app/rental', RentalHome::class)->name('rental.home');

    // Orders — bespoke list (status tabs + date search) and contract form.
    Route::get('/app/rental/order/export/csv', [RentalOrderExportController::class, 'csv'])->name('rental.order.export.csv');
    Route::get('/app/rental/order/export/excel', [RentalOrderExportController::class, 'excel'])->name('rental.order.export.excel');
    Route::get('/app/rental/order/export/pdf', [RentalOrderExportController::class, 'pdf'])->name('rental.order.export.pdf');
    Route::get('/app/rental/order/export/print', [RentalOrderExportController::class, 'print'])->name('rental.order.export.print');
    Route::post('/app/rental/order/import', RentalOrderImportController::class)->name('rental.order.import');
    Route::get('/app/rental/order', Orders::class)->name('rental.order.index');
    Route::get('/app/rental/order/new', OrderForm::class)->name('rental.order.create');
    // Printable Car Hire Agreement overlay (distinct path from the {id} editor).
    Route::get('/app/rental/order/{id}/agreement', RentalAgreementPrintController::class)
        ->whereNumber('id')->name('rental.order.agreement');
    // Self-contained agreement PDF (emailed to the customer; downloadable here).
    Route::get('/app/rental/order/{id}/agreement/pdf', RentalAgreementPdfController::class)
        ->whereNumber('id')->name('rental.order.agreement.pdf');
    Route::get('/app/rental/order/{id}', OrderForm::class)->whereNumber('id')->name('rental.order.edit');

    // Quotations — estimates that convert into orders.
    Route::get('/app/rental/quotation/export/csv', [RentalQuotationExportController::class, 'csv'])->name('rental.quotation.export.csv');
    Route::get('/app/rental/quotation/export/excel', [RentalQuotationExportController::class, 'excel'])->name('rental.quotation.export.excel');
    Route::get('/app/rental/quotation/export/pdf', [RentalQuotationExportController::class, 'pdf'])->name('rental.quotation.export.pdf');
    Route::get('/app/rental/quotation/export/print', [RentalQuotationExportController::class, 'print'])->name('rental.quotation.export.print');
    Route::get('/app/rental/quotation', Quotations::class)->name('rental.quotation.index');
    Route::get('/app/rental/quotation/new', QuotationForm::class)->name('rental.quotation.create');
    Route::get('/app/rental/quotation/{id}', QuotationForm::class)->whereNumber('id')->name('rental.quotation.edit');

    // Invoices — bills (one-click from an order, or standalone).
    Route::get('/app/rental/invoice/export/csv', [RentalInvoiceExportController::class, 'csv'])->name('rental.invoice.export.csv');
    Route::get('/app/rental/invoice/export/excel', [RentalInvoiceExportController::class, 'excel'])->name('rental.invoice.export.excel');
    Route::get('/app/rental/invoice/export/pdf', [RentalInvoiceExportController::class, 'pdf'])->name('rental.invoice.export.pdf');
    Route::get('/app/rental/invoice/export/print', [RentalInvoiceExportController::class, 'print'])->name('rental.invoice.export.print');
    Route::get('/app/rental/invoice', Invoices::class)->name('rental.invoice.index');
    Route::get('/app/rental/invoice/new', InvoiceForm::class)->name('rental.invoice.create');
    Route::get('/app/rental/invoice/{id}', InvoiceForm::class)->whereNumber('id')->name('rental.invoice.edit');

    // Receipts — payments against invoices.
    Route::get('/app/rental/receipt/export/csv', [RentalReceiptExportController::class, 'csv'])->name('rental.receipt.export.csv');
    Route::get('/app/rental/receipt/export/excel', [RentalReceiptExportController::class, 'excel'])->name('rental.receipt.export.excel');
    Route::get('/app/rental/receipt/export/pdf', [RentalReceiptExportController::class, 'pdf'])->name('rental.receipt.export.pdf');
    Route::get('/app/rental/receipt/export/print', [RentalReceiptExportController::class, 'print'])->name('rental.receipt.export.print');
    Route::get('/app/rental/receipt', Receipts::class)->name('rental.receipt.index');
    Route::get('/app/rental/receipt/new', ReceiptForm::class)->name('rental.receipt.create');
    Route::get('/app/rental/receipt/{id}', ReceiptForm::class)->whereNumber('id')->name('rental.receipt.edit');

    // Replacements — swap a customer's car for another.
    Route::get('/app/rental/replacement/export/csv', [RentalReplacementExportController::class, 'csv'])->name('rental.replacement.export.csv');
    Route::get('/app/rental/replacement/export/excel', [RentalReplacementExportController::class, 'excel'])->name('rental.replacement.export.excel');
    Route::get('/app/rental/replacement/export/pdf', [RentalReplacementExportController::class, 'pdf'])->name('rental.replacement.export.pdf');
    Route::get('/app/rental/replacement/export/print', [RentalReplacementExportController::class, 'print'])->name('rental.replacement.export.print');
    Route::get('/app/rental/replacement', Replacements::class)->name('rental.replacement.index');
    Route::get('/app/rental/replacement/new', ReplacementForm::class)->name('rental.replacement.create');
    Route::get('/app/rental/replacement/{id}', ReplacementForm::class)->whereNumber('id')->name('rental.replacement.edit');

    // Maintenance — service / repair records.
    Route::get('/app/rental/maintenance/export/csv', [RentalMaintenanceExportController::class, 'csv'])->name('rental.maintenance.export.csv');
    Route::get('/app/rental/maintenance/export/excel', [RentalMaintenanceExportController::class, 'excel'])->name('rental.maintenance.export.excel');
    Route::get('/app/rental/maintenance/export/pdf', [RentalMaintenanceExportController::class, 'pdf'])->name('rental.maintenance.export.pdf');
    Route::get('/app/rental/maintenance/export/print', [RentalMaintenanceExportController::class, 'print'])->name('rental.maintenance.export.print');
    Route::get('/app/rental/maintenance', MaintenanceRecords::class)->name('rental.maintenance.index');
    Route::get('/app/rental/maintenance/new', MaintenanceForm::class)->name('rental.maintenance.create');
    Route::get('/app/rental/maintenance/{id}', MaintenanceForm::class)->whereNumber('id')->name('rental.maintenance.edit');

    // Reports — summary / orders / vehicles / customers (+ CSV export). The
    // export route is declared before the page so it isn't shadowed.
    Route::get('/app/rental/reports/orders/export', RentalReportExportController::class)->name('rental.reports.export');
    Route::get('/app/rental/reports', Reports::class)->name('rental.reports');

    // Sales — booking-revenue matrix by car/month + seasonal analysis.
    Route::get('/app/rental/sales/export', RentalSalesExportController::class)->name('rental.sales.export');
    Route::post('/app/rental/sales/import', RentalSalesImportController::class)->name('rental.sales.import');
    Route::get('/app/rental/sales', Sales::class)->name('rental.sales');

    // Masters. `new` is declared before the numeric {id} so it isn't
    // captured as an id (same convention as the other modules).
    Route::get('/app/rental/branch', Branches::class)->name('rental.branch.index');
    Route::get('/app/rental/branch/new', BranchForm::class)->name('rental.branch.create');
    Route::get('/app/rental/branch/{id}', BranchForm::class)->whereNumber('id')->name('rental.branch.edit');

    Route::post('/app/rental/customer/import', RentalCustomerImportController::class)->name('rental.customer.import');
    Route::get('/app/rental/customer', Customers::class)->name('rental.customer.index');
    Route::get('/app/rental/customer/new', CustomerForm::class)->name('rental.customer.create');
    Route::get('/app/rental/customer/{id}', CustomerForm::class)->whereNumber('id')->name('rental.customer.edit');

    Route::post('/app/rental/vehicle/import', RentalVehicleImportController::class)->name('rental.vehicle.import');
    Route::get('/app/rental/vehicle', Vehicles::class)->name('rental.vehicle.index');
    Route::get('/app/rental/vehicle/new', VehicleForm::class)->name('rental.vehicle.create');
    Route::get('/app/rental/vehicle/{id}', VehicleForm::class)->whereNumber('id')->name('rental.vehicle.edit');

    Route::post('/app/rental/driver/import', RentalDriverImportController::class)->name('rental.driver.import');
    Route::get('/app/rental/driver', Drivers::class)->name('rental.driver.index');
    Route::get('/app/rental/driver/new', DriverForm::class)->name('rental.driver.create');
    Route::get('/app/rental/driver/{id}', DriverForm::class)->whereNumber('id')->name('rental.driver.edit');
});
