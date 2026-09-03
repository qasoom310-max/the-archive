<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Modules\Limousine\Http\Controllers\LimoInvoiceImportController;
use Modules\Limousine\Http\Controllers\LimoReceiptImportController;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoReceipt;
use Modules\Limousine\Support\InvoiceImporter as LimoInvoiceImporter;
use Modules\Limousine\Support\ReceiptImporter as LimoReceiptImporter;
use Modules\Rental\Http\Controllers\RentalInvoiceImportController;
use Modules\Rental\Http\Controllers\RentalReceiptImportController;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalInvoice;
use Modules\Rental\Models\RentalReceipt;
use Modules\Rental\Support\InvoiceImporter as RentalInvoiceImporter;
use Modules\Rental\Support\ReceiptImporter as RentalReceiptImporter;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Importing an invoices or receipts CSV (an export from a previous system,
 * the same shape each screen's own export already prints). Every row lands
 * as SETTLED HISTORY — no order/trip behind it, no recompute against today's
 * pricing. A receipt whose Invoice column names an on-file invoice links to
 * it (and that invoice's amount_paid/status recompute from the sum of its
 * receipts, same as the live "receive payment" flow); otherwise it stands
 * alone.
 */
final class InvoiceReceiptImportTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function csv(string $header, string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'invrcpt') . '.csv';
        file_put_contents($path, $header . "\n" . $body);

        return $path;
    }

    // --- Limousine invoices ---------------------------------------------

    public function test_limousine_invoice_imports_with_customer_and_computed_status(): void
    {
        app(ModuleManager::class)->install('limousine');
        $path = $this->csv('Reference,Customer,Issued,Total,Paid,Status', "INV/00099,Dadabhai Travel,2026-01-10,100,40,\n");

        $result = app(LimoInvoiceImporter::class)->import($path);
        $this->assertSame(1, $result['imported']);

        $invoice = LimoInvoice::query()->firstOrFail();
        $this->assertSame('Dadabhai Travel', $invoice->customer->name);
        $this->assertEqualsWithDelta(100.0, $invoice->total, 0.001);
        $this->assertEqualsWithDelta(40.0, $invoice->amount_paid, 0.001);
        $this->assertSame(LimoInvoice::STATUS_PARTIAL, $invoice->status);
        $this->assertNull($invoice->booking_id);
        $this->assertNull($invoice->quotation_id);
    }

    public function test_limousine_invoice_reuses_an_existing_customer(): void
    {
        app(ModuleManager::class)->install('limousine');
        $existing = LimoCustomer::query()->create(['name' => 'Dadabhai Travel', 'type' => 'company']);
        app(LimoInvoiceImporter::class)->import($this->csv('Reference,Customer,Issued,Total,Paid,Status', "INV/00099,Dadabhai Travel,,100,,\n"));

        $this->assertSame(1, LimoCustomer::query()->count());
        $this->assertSame($existing->id, LimoInvoice::query()->firstOrFail()->customer_id);
    }

    public function test_limousine_invoice_re_import_does_not_duplicate(): void
    {
        app(ModuleManager::class)->install('limousine');
        app(LimoInvoiceImporter::class)->import($this->csv('Reference,Customer,Issued,Total,Paid,Status', "I/1,Dadabhai,2026-01-10,100,,\n"));
        $second = app(LimoInvoiceImporter::class)->import($this->csv('Reference,Customer,Issued,Total,Paid,Status', "I/1,Dadabhai,2026-01-10,100,,\n"));

        $this->assertSame(0, $second['imported']);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(1, LimoInvoice::query()->count());
    }

    public function test_limousine_invoice_import_is_manager_gated(): void
    {
        app(ModuleManager::class)->install('limousine');
        $this->actingAs(User::factory()->create());
        $controller = new LimoInvoiceImportController();

        $file = new UploadedFile($this->csv('Reference,Customer,Issued,Total,Paid,Status', "X,X,,100,,\n"), 'i.csv', 'text/csv', null, true);
        $request = Request::create('/x', 'POST', [], [], ['file' => $file]);

        try {
            $controller($request, app(LimoInvoiceImporter::class));
            $this->fail('A non-manager should be forbidden.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, LimoInvoice::query()->count());
    }

    // --- Limousine receipts ----------------------------------------------

    public function test_limousine_receipt_links_to_an_existing_invoice_and_lands_pre_confirmed(): void
    {
        app(ModuleManager::class)->install('limousine');
        $customer = LimoCustomer::query()->create(['name' => 'Dadabhai Travel', 'type' => 'company']);
        $invoice = LimoInvoice::query()->create([
            'reference' => 'INV/00050', 'customer_id' => $customer->id, 'total' => 100, 'subtotal' => 100,
        ]);

        $path = $this->csv('Reference,Customer,Invoice,Date,Method,Amount', "RCP/1,Dadabhai Travel,INV/00050,2026-01-11,cash,100\n");
        $result = app(LimoReceiptImporter::class)->import($path);
        $this->assertSame(1, $result['imported']);

        $receipt = LimoReceipt::query()->firstOrFail();
        $this->assertSame($invoice->id, $receipt->invoice_id);
        $this->assertTrue($receipt->isConfirmed());

        $invoice->refresh();
        $this->assertEqualsWithDelta(100.0, $invoice->amount_paid, 0.001);
        $this->assertSame(LimoInvoice::STATUS_PAID, $invoice->status);
    }

    public function test_limousine_receipt_with_no_matching_invoice_stands_alone(): void
    {
        app(ModuleManager::class)->install('limousine');
        app(LimoReceiptImporter::class)->import($this->csv('Reference,Customer,Invoice,Date,Method,Amount', "RCP/1,Walk-in,,2026-01-11,cash,25\n"));

        $receipt = LimoReceipt::query()->firstOrFail();
        $this->assertNull($receipt->invoice_id);
        $this->assertTrue($receipt->isConfirmed());
    }

    public function test_limousine_receipt_re_import_does_not_duplicate(): void
    {
        app(ModuleManager::class)->install('limousine');
        app(LimoReceiptImporter::class)->import($this->csv('Reference,Customer,Invoice,Date,Method,Amount', "R/1,Dadabhai,,2026-01-11,cash,25\n"));
        $second = app(LimoReceiptImporter::class)->import($this->csv('Reference,Customer,Invoice,Date,Method,Amount', "R/1,Dadabhai,,2026-01-11,cash,25\n"));

        $this->assertSame(0, $second['imported']);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(1, LimoReceipt::query()->count());
    }

    public function test_limousine_receipt_import_is_manager_gated(): void
    {
        app(ModuleManager::class)->install('limousine');
        $this->actingAs(User::factory()->create());
        $controller = new LimoReceiptImportController();

        $file = new UploadedFile($this->csv('Reference,Customer,Invoice,Date,Method,Amount', "X,X,,,cash,25\n"), 'r.csv', 'text/csv', null, true);
        $request = Request::create('/x', 'POST', [], [], ['file' => $file]);

        try {
            $controller($request, app(LimoReceiptImporter::class));
            $this->fail('A non-manager should be forbidden.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, LimoReceipt::query()->count());
    }

    // --- Rental invoices ---------------------------------------------------

    public function test_rental_invoice_imports_with_customer_and_computed_status(): void
    {
        app(ModuleManager::class)->install('rental');
        $path = $this->csv('Reference,Customer,Issued,Total,Paid,Status', "INV/00099,Qassim,2026-01-10,60,60,\n");

        $result = app(RentalInvoiceImporter::class)->import($path);
        $this->assertSame(1, $result['imported']);

        $invoice = RentalInvoice::query()->firstOrFail();
        $this->assertSame('Qassim', $invoice->customer->name);
        $this->assertEqualsWithDelta(60.0, $invoice->total, 0.001);
        $this->assertSame(RentalInvoice::STATUS_PAID, $invoice->status);
        $this->assertNull($invoice->order_id);
    }

    public function test_rental_invoice_reuses_an_existing_customer(): void
    {
        app(ModuleManager::class)->install('rental');
        $existing = RentalCustomer::query()->create(['name' => 'Qassim', 'phone' => '39000001']);
        app(RentalInvoiceImporter::class)->import($this->csv('Reference,Customer,Issued,Total,Paid,Status', "INV/00099,Qassim,,60,,\n"));

        $this->assertSame(1, RentalCustomer::query()->count());
        $this->assertSame($existing->id, RentalInvoice::query()->firstOrFail()->customer_id);
    }

    public function test_rental_invoice_re_import_does_not_duplicate(): void
    {
        app(ModuleManager::class)->install('rental');
        app(RentalInvoiceImporter::class)->import($this->csv('Reference,Customer,Issued,Total,Paid,Status', "I/1,Qassim,2026-01-10,60,,\n"));
        $second = app(RentalInvoiceImporter::class)->import($this->csv('Reference,Customer,Issued,Total,Paid,Status', "I/1,Qassim,2026-01-10,60,,\n"));

        $this->assertSame(0, $second['imported']);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(1, RentalInvoice::query()->count());
    }

    public function test_rental_invoice_import_is_manager_gated(): void
    {
        app(ModuleManager::class)->install('rental');
        $this->actingAs(User::factory()->create());
        $controller = new RentalInvoiceImportController();

        $file = new UploadedFile($this->csv('Reference,Customer,Issued,Total,Paid,Status', "X,X,,60,,\n"), 'i.csv', 'text/csv', null, true);
        $request = Request::create('/x', 'POST', [], [], ['file' => $file]);

        try {
            $controller($request, app(RentalInvoiceImporter::class));
            $this->fail('A non-manager should be forbidden.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, RentalInvoice::query()->count());
    }

    // --- Rental receipts -----------------------------------------------

    public function test_rental_receipt_links_to_an_existing_invoice_by_reference(): void
    {
        app(ModuleManager::class)->install('rental');
        $customer = RentalCustomer::query()->create(['name' => 'Qassim', 'phone' => '39000001']);
        $invoice = RentalInvoice::query()->create([
            'reference' => 'INV/00050', 'customer_id' => $customer->id, 'total' => 60, 'subtotal' => 60,
        ]);

        app(RentalReceiptImporter::class)->import($this->csv('Reference,Customer,Invoice,Date,Method,Amount', "RCP/1,Qassim,INV/00050,2026-01-11,cash,60\n"));

        $receipt = RentalReceipt::query()->firstOrFail();
        $this->assertSame($invoice->id, $receipt->invoice_id);

        $invoice->refresh();
        $this->assertEqualsWithDelta(60.0, $invoice->amount_paid, 0.001);
        $this->assertSame(RentalInvoice::STATUS_PAID, $invoice->status);
    }

    public function test_rental_receipt_with_no_matching_invoice_stands_alone(): void
    {
        app(ModuleManager::class)->install('rental');
        app(RentalReceiptImporter::class)->import($this->csv('Reference,Customer,Invoice,Date,Method,Amount', "RCP/1,Walk-in,,2026-01-11,cash,25\n"));

        $receipt = RentalReceipt::query()->firstOrFail();
        $this->assertNull($receipt->invoice_id);
    }

    public function test_rental_receipt_re_import_does_not_duplicate(): void
    {
        app(ModuleManager::class)->install('rental');
        app(RentalReceiptImporter::class)->import($this->csv('Reference,Customer,Invoice,Date,Method,Amount', "R/1,Qassim,,2026-01-11,cash,25\n"));
        $second = app(RentalReceiptImporter::class)->import($this->csv('Reference,Customer,Invoice,Date,Method,Amount', "R/1,Qassim,,2026-01-11,cash,25\n"));

        $this->assertSame(0, $second['imported']);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(1, RentalReceipt::query()->count());
    }

    public function test_rental_receipt_import_is_manager_gated(): void
    {
        app(ModuleManager::class)->install('rental');
        $this->actingAs(User::factory()->create());
        $controller = new RentalReceiptImportController();

        $file = new UploadedFile($this->csv('Reference,Customer,Invoice,Date,Method,Amount', "X,X,,,cash,25\n"), 'r.csv', 'text/csv', null, true);
        $request = Request::create('/x', 'POST', [], [], ['file' => $file]);

        try {
            $controller($request, app(RentalReceiptImporter::class));
            $this->fail('A non-manager should be forbidden.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, RentalReceipt::query()->count());
    }
}
