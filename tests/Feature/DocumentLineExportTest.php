<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Modules\Limousine\Http\Controllers\LimoInvoiceExportController;
use Modules\Limousine\Http\Controllers\LimoQuotationExportController;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoQuotation;
use Modules\Rental\Http\Controllers\RentalInvoiceExportController;
use Modules\Rental\Http\Controllers\RentalQuotationExportController;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalInvoice;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\RentalQuotation;
use Modules\Rental\Models\Vehicle;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * The lists whose PDF is a real document (invoices, quotations) download a
 * sheet with that document's own columns, one row per line, so the Excel
 * reads like the paper the customer gets.
 */
final class DocumentLineExportTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
        app(ModuleManager::class)->install('rental');
    }

    private function streamed(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    /** @return list<list<string>> */
    private function csv(StreamedResponse $response): array
    {
        $body = ltrim($this->streamed($response), "\xEF\xBB\xBF");

        return array_map(static fn (string $line): array => str_getcsv($line), array_values(array_filter(explode("\n", $body), static fn (string $l): bool => $l !== '')));
    }

    public function test_the_limousine_invoice_sheet_has_the_printed_invoice_columns_and_one_row_per_trip(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Braxtone Plus W.L.L', 'type' => 'company']);
        $booking = LimoBooking::query()->create([
            'customer_id' => $customer->id, 'fare' => 22,
            'pax_name' => 'Abdul Jalil', 'company_reference' => 'BTRSA/0926/3559',
        ]);
        $booking->legs()->create([
            'sequence' => 0, 'service_type' => 'transfer', 'from_location' => 'Maqaba Villa 38',
            'to_location' => 'Bahrain Airport', 'start_at' => '2026-10-01 19:00:00', 'days' => 1,
            'vehicle_details' => 'Sedan', 'rate' => 8, 'rate_basis' => 'trip', 'net_amount' => 8,
        ]);
        $booking->legs()->create([
            'sequence' => 1, 'service_type' => 'transfer', 'from_location' => 'Isa Town H 4837',
            'to_location' => 'Bahrain Airport', 'start_at' => '2026-10-02 13:15:00', 'days' => 1,
            'vehicle_details' => 'Suv', 'rate' => 14, 'rate_basis' => 'trip', 'net_amount' => 14,
        ]);
        $invoice = $booking->syncInvoice();

        $rows = $this->csv(app(LimoInvoiceExportController::class)->csv(Request::create('/x', 'GET')));

        $this->assertSame(['Sl', 'Ref. #', 'Service', 'Car Type', 'From', 'To', 'Service date', 'Company ref.', 'Pax name', 'Total', 'Invoice', 'Customer'], $rows[0]);
        $this->assertSame('1', $rows[1][0]);
        $this->assertSame('Transfer', $rows[1][2]);
        $this->assertSame('Sedan', $rows[1][3]);
        $this->assertSame('Maqaba Villa 38', $rows[1][4]);
        $this->assertSame('01-Oct-26 19:00', $rows[1][6]);
        $this->assertSame('BTRSA/0926/3559', $rows[1][7]);
        $this->assertSame('Abdul Jalil', $rows[1][8]);
        $this->assertStringContainsString('8.00', $rows[1][9]);
        $this->assertSame($invoice->reference, $rows[1][10]);
        $this->assertSame('Braxtone Plus W.L.L', $rows[1][11]);
        $this->assertSame('Isa Town H 4837', $rows[2][4]);

        // The totals box at the foot, as on the document.
        $labels = array_column($rows, 8);
        $this->assertContains('Total', $labels);
        $this->assertContains('Balance due', $labels);

        // The Excel carries the very same sheet.
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $this->streamed(app(LimoInvoiceExportController::class)->excel(Request::create('/x', 'GET'))));
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet()->toArray();
        @unlink($path);
        $this->assertSame($rows[0], $sheet[0]);
        $this->assertSame('Maqaba Villa 38', $sheet[1][4]);
    }

    public function test_the_limousine_quotation_sheet_has_the_printed_quotation_columns(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Gulf Air', 'type' => 'company']);
        $quote = LimoQuotation::query()->create(['customer_id' => $customer->id, 'fare' => 90]);
        $quote->legs()->create([
            'sequence' => 0, 'service_type' => 'chauffeur', 'from_location' => 'Hotel',
            'to_location' => '', 'start_at' => '2026-10-05 09:00:00', 'days' => 2, 'hours' => 5,
            'vehicle_details' => 'GMC Yukon', 'rate' => 45, 'rate_basis' => 'day', 'net_amount' => 90,
        ]);

        $rows = $this->csv(app(LimoQuotationExportController::class)->csv(Request::create('/x', 'GET')));

        $this->assertSame(['No.', 'Service', 'Vehicle Type', 'From', 'To', 'Hours/Day', 'Days/Trips', 'Unit', 'Rate per Unit', 'Amount', 'Quotation', 'Customer'], $rows[0]);
        $this->assertSame(['1', 'Chauffeur', 'GMC Yukon', 'Hotel', '', '5', '2', '1'], array_slice($rows[1], 0, 8));
        $this->assertStringContainsString('90.00', $rows[1][9]);
        $this->assertSame($quote->reference, $rows[1][10]);
        $this->assertContains('Total', array_column($rows, 8));
    }

    public function test_the_rental_invoice_sheet_has_the_printed_invoice_columns(): void
    {
        $customer = RentalCustomer::query()->create(['name' => 'Saad AlOtaibi']);
        $vehicle = Vehicle::query()->create(['name' => 'Ford Taurus', 'plate_no' => '111629', 'make' => 'Ford', 'model' => 'Taurus', 'daily_rate' => 50]);
        $order = RentalOrder::query()->create([
            'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'order_date' => '2026-11-20', 'start_date' => '2026-11-20', 'end_date' => '2026-11-22',
            'rate_type' => 'daily', 'rate' => 50, 'vat_rate' => 0, 'state' => RentalOrder::STATE_ACTIVE,
        ]);
        $order->recalcTotals();
        $order->save();
        $invoice = RentalInvoice::query()->create([
            'customer_id' => $customer->id, 'order_id' => $order->id,
            'subtotal' => $order->subtotal, 'total' => $order->total,
        ]);

        $rows = $this->csv(app(RentalInvoiceExportController::class)->csv(Request::create('/x', 'GET')));

        $this->assertSame(['Service', 'Vehicle', 'From', 'To', 'Days/Trips', 'Rate per Unit', 'Amount', 'Invoice', 'Customer'], $rows[0]);
        $this->assertSame('Rental', $rows[1][0]);
        $this->assertSame('111629 Ford Taurus', $rows[1][1]);
        $this->assertSame('20-11-2026', $rows[1][2]);
        $this->assertSame('2 days', $rows[1][4]);
        $this->assertStringContainsString('100.00', $rows[1][6]);
        $this->assertSame($invoice->reference, $rows[1][7]);
        $this->assertContains('Balance due', array_column($rows, 5));
    }

    public function test_the_rental_quotation_sheet_has_one_row_per_car_line(): void
    {
        $customer = RentalCustomer::query()->create(['name' => 'Saad AlOtaibi']);
        $ford = Vehicle::query()->create(['name' => 'Ford Taurus', 'plate_no' => '111629', 'make' => 'Ford', 'model' => 'Taurus', 'daily_rate' => 50]);
        $nissan = Vehicle::query()->create(['name' => 'Nissan Sunny', 'plate_no' => '222', 'make' => 'Nissan', 'model' => 'Sunny', 'daily_rate' => 30]);
        RentalQuotation::query()->create([
            'customer_id' => $customer->id, 'vehicle_id' => $ford->id, 'rate' => 50, 'quantity' => 1,
            'extra_cars' => [['vehicle_id' => $nissan->id, 'rate' => 30, 'qty' => 3]],
            'start_date' => '2026-11-20', 'end_date' => '2026-11-22', 'rate_type' => 'daily',
        ]);

        $rows = $this->csv(app(RentalQuotationExportController::class)->csv(Request::create('/x', 'GET')));

        $this->assertSame(['No.', 'Service', 'Vehicle', 'From', 'To', 'Days', 'Rate', 'Amount', 'Quotation', 'Customer'], $rows[0]);
        $this->assertSame('111629 Ford Taurus', $rows[1][2]);
        $this->assertSame('2', $rows[2][0]);
        $this->assertSame('3 × Nissan Sunny', $rows[2][2]);
    }
}
