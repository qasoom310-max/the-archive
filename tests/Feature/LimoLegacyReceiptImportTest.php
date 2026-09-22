<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoReceipt;
use Modules\Limousine\Support\LegacyReceiptImporter;
use Tests\TestCase;

/**
 * Bringing receipts over from the previous system's own receipts export: a
 * row links by BOOKING NUMBER (not an invoice reference, which this export
 * never printed), the old RCPT No. is kept verbatim so a re-run only picks up
 * what is new, and a booking not (yet) on file still records the receipt,
 * standalone, against the customer named on the row.
 */
final class LimoLegacyReceiptImportTest extends TestCase
{
    use DatabaseMigrations;

    private const HEADER = '"Sl No.","RCPT No.","Date","Booking #","Customer","Amount","Pay type","Comments","Actions"';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private function csv(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lgr').'.csv';
        file_put_contents($path, self::HEADER."\n".$body);

        return $path;
    }

    private function row(string $rcpt, string $date, string $booking, string $customer, string $amount, string $payType, string $comments = ''): string
    {
        return '"1","'.$rcpt.'","'.$date.'","'.$booking.'","'.$customer.'","'.$amount.'","'.$payType.'","'.$comments.'"," View-->  Print "'."\n";
    }

    private function booking(int $number, string $customerName, float $fare): LimoBooking
    {
        $customer = LimoCustomer::query()->create(['name' => $customerName, 'active' => true]);

        $booking = new LimoBooking;
        $booking->forceFill([
            'id' => $number,
            'customer_id' => $customer->id,
            'pickup_at' => '2026-09-01 10:00:00',
            'fare' => $fare,
            'amount' => $fare,
            'status' => LimoBooking::STATUS_COMPLETED,
            'payment_status' => LimoBooking::PAYMENT_UNPAID,
        ]);
        $booking->save();

        return $booking;
    }

    public function test_a_receipt_links_to_its_bookings_existing_invoice_and_settles_it(): void
    {
        $booking = $this->booking(14739, 'Abdullah Mohammed', 65.0);
        $invoice = $booking->syncInvoice();

        $result = app(LegacyReceiptImporter::class)->import($this->csv(
            $this->row('L-RCPT12968', '12-Aug-2026', '14739', 'abdullah', '65.000', 'Cash'),
        ));

        $this->assertSame(1, $result['imported']);
        $this->assertSame(0, $result['skipped']);
        $this->assertStringStartsWith('NEW', $result['lines'][0]);

        $receipt = LimoReceipt::query()->where('reference', 'L-RCPT12968')->sole();
        $this->assertSame($booking->id, $receipt->booking_id);
        $this->assertSame($invoice->id, $receipt->invoice_id);
        $this->assertSame($booking->customer_id, $receipt->customer_id);
        $this->assertSame('2026-08-12', $receipt->date?->format('Y-m-d'));
        $this->assertEqualsWithDelta(65.0, $receipt->amount, 0.001);
        $this->assertSame('cash', $receipt->method);
        $this->assertEqualsWithDelta(0.0, (float) $receipt->balance_after, 0.001);
        $this->assertNotNull($receipt->confirmed_at);
        $this->assertSame('Import (previous system)', $receipt->confirmed_by);
        $this->assertStringContainsString('Booking '.$booking->reference, (string) $receipt->notes);

        $this->assertSame(LimoInvoice::STATUS_PAID, $invoice->refresh()->status);
    }

    public function test_a_booking_with_no_invoice_yet_gets_a_standalone_receipt_with_no_balance(): void
    {
        $booking = $this->booking(15437, 'Shahad', 13.0);

        app(LegacyReceiptImporter::class)->import($this->csv(
            $this->row('L-RCPT12965', '11-Sep-2026', '15437', 'Shahad', '13.000', 'Online'),
        ));

        $receipt = LimoReceipt::query()->where('reference', 'L-RCPT12965')->sole();
        $this->assertSame($booking->id, $receipt->booking_id);
        $this->assertNull($receipt->invoice_id);
        $this->assertNull($receipt->balance_after);
        $this->assertSame('online', $receipt->method);
    }

    public function test_a_booking_not_on_file_still_imports_standalone_and_resolves_the_customer_by_name(): void
    {
        $result = app(LegacyReceiptImporter::class)->import($this->csv(
            $this->row('L-RCPT99001', '10-Sep-2026', '99999', 'Someone New', '20.000', 'Cash'),
        ));

        $this->assertSame(1, $result['imported']);
        $this->assertStringContainsString('not on file', $result['lines'][0]);

        $receipt = LimoReceipt::query()->where('reference', 'L-RCPT99001')->sole();
        $this->assertNull($receipt->booking_id);
        $this->assertNull($receipt->invoice_id);
        $this->assertSame('Someone New', $receipt->customer?->name);
        $this->assertStringContainsString('Booking #99999 (not on file)', (string) $receipt->notes);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string|null}>
     */
    public static function payTypeProvider(): array
    {
        return [
            'Cash' => ['Cash', 'cash', null],
            'BenefitPay' => ['BenefitPay', 'benefit', null],
            'Credit Card' => ['Credit Card', 'card', null],
            'Online' => ['Online', 'online', null],
            'Cheque' => ['Cheque', 'transfer', 'Cheque'],
            'Advance' => ['Advance', 'cash', 'Advance'],
            'Unknown' => ['Wire', 'cash', 'Wire'],
        ];
    }

    /**
     * @dataProvider payTypeProvider
     */
    public function test_pay_type_maps_onto_the_apps_method_set(string $payType, string $expectedMethod, ?string $expectedNoteFragment): void
    {
        app(LegacyReceiptImporter::class)->import($this->csv(
            $this->row('L-RCPT1', '10-Sep-2026', '', 'Walk-in', '10.000', $payType),
        ));

        $receipt = LimoReceipt::query()->where('reference', 'L-RCPT1')->sole();
        $this->assertSame($expectedMethod, $receipt->method);

        if ($expectedNoteFragment !== null) {
            $this->assertStringContainsString($expectedNoteFragment, (string) $receipt->notes);
        }
    }

    public function test_a_receipt_already_on_file_is_skipped_including_a_duplicate_within_the_same_file(): void
    {
        $importer = app(LegacyReceiptImporter::class);
        $importer->import($this->csv($this->row('L-RCPT12943', '08-Sep-2026', '', 'City connect', '72.000', 'BenefitPay')));

        $again = $importer->import($this->csv(
            $this->row('L-RCPT12943', '08-Sep-2026', '', 'City connect', '72.000', 'BenefitPay')
            .$this->row('L-RCPT12943', '08-Sep-2026', '', 'City connect', '72.000', 'BenefitPay'),
        ));

        $this->assertSame(0, $again['imported']);
        $this->assertSame(2, $again['skipped']);
        $this->assertStringStartsWith('EXISTS', $again['lines'][0]);
        $this->assertStringStartsWith('EXISTS', $again['lines'][1]);
        $this->assertSame(1, LimoReceipt::query()->where('reference', 'L-RCPT12943')->count());
    }

    /** The old system reused numbers: same L-RCPT, different payment, both are real money. */
    public function test_a_reused_receipt_number_for_a_different_payment_is_still_imported(): void
    {
        $result = app(LegacyReceiptImporter::class)->import($this->csv(
            $this->row('L-RCPT12666', '07-Aug-2026', '15170', 'Rony Tabbal', '45.000', 'Cash')
            .$this->row('L-RCPT12666', '07-Aug-2026', '13871', 'WELLGATE SERVICES LP', '20.000', 'Online'),
        ));

        $this->assertSame(2, $result['imported']);
        $this->assertStringContainsString('number reused', $result['lines'][1]);
        $this->assertSame(2, LimoReceipt::query()->where('reference', 'L-RCPT12666')->count());
    }

    /** One Import button takes both the old receipts register and the ERP's own export. */
    public function test_the_import_button_takes_the_old_register_and_the_erp_export(): void
    {
        $controller = new \Modules\Limousine\Http\Controllers\LimoReceiptImportController();
        $upload = fn (string $path): \Illuminate\Http\Request => \Illuminate\Http\Request::create('/x', 'POST', [], [], [
            'file' => new \Illuminate\Http\UploadedFile($path, 'r.csv', 'text/csv', null, true),
        ]);

        $controller($upload($this->csv($this->row('L-RCPT12978', '20-Sep-2026', '14904', 'Braxtone Plus W.L.L', '8.000', 'BenefitPay'))), app(\Modules\Limousine\Support\ReceiptImporter::class));
        $this->assertSame('benefit', LimoReceipt::query()->where('reference', 'L-RCPT12978')->sole()->method);

        $invoice = new LimoInvoice;
        $invoice->forceFill([
            'id' => 1369, 'reference' => 'INV/01369',
            'customer_id' => LimoCustomer::query()->create(['name' => 'Jackline Ngotya'])->id,
            'subtotal' => 12, 'total' => 12, 'status' => LimoInvoice::STATUS_UNPAID,
        ])->save();

        $erpPath = tempnam(sys_get_temp_dir(), 'erpr').'.csv';
        file_put_contents($erpPath, "Reference,Customer,Invoice,Date,Method,Amount,Confirmed,\"Created by\"\n"
            ."RCP/12862,\"Jackline Ngotya\",INV/01369,20-Sep-2026,Benefit,\"12.00 BD\",Unconfirmed,\"Hasan Makhlooq\"\n"
            ."RCP/12857,\"Fouad Aldossary\",,18-Sep-2026,Credit_card,\"50.00 BD\",Confirmed,\n");
        $controller($upload($erpPath), app(\Modules\Limousine\Support\ReceiptImporter::class));
        $controller($upload($erpPath), app(\Modules\Limousine\Support\ReceiptImporter::class));

        $kept = LimoReceipt::query()->where('reference', 'RCP/12862')->sole();
        $this->assertSame(12862, $kept->id);
        $this->assertFalse($kept->isConfirmed());
        $this->assertSame('Hasan Makhlooq', $kept->prepared_by);
        $this->assertSame(1369, $kept->invoice_id);
        $this->assertSame(LimoInvoice::STATUS_PAID, $invoice->refresh()->status);

        $card = LimoReceipt::query()->where('reference', 'RCP/12857')->sole();
        $this->assertTrue($card->isConfirmed());
        $this->assertSame('card', $card->method);
        $this->assertSame(3, LimoReceipt::query()->count());
    }

    public function test_rows_missing_a_receipt_number_customer_or_amount_are_skipped(): void
    {
        $result = app(LegacyReceiptImporter::class)->import($this->csv(
            $this->row('', '10-Sep-2026', '', 'Someone', '10.000', 'Cash')
            .$this->row('L-RCPT2', '10-Sep-2026', '', '', '10.000', 'Cash')
            .$this->row('L-RCPT3', '10-Sep-2026', '', 'Someone', '0.000', 'Cash'),
        ));

        $this->assertSame(0, $result['imported']);
        $this->assertSame(3, $result['skipped']);
        foreach ($result['lines'] as $line) {
            $this->assertStringStartsWith('SKIP', $line);
        }
    }

    public function test_a_dry_run_saves_nothing(): void
    {
        $this->booking(14739, 'Abdullah Mohammed', 65.0);

        $result = app(LegacyReceiptImporter::class)->import(
            $this->csv($this->row('L-RCPT12968', '12-Aug-2026', '14739', 'abdullah', '65.000', 'Cash')),
            pretend: true,
        );

        $this->assertSame(1, $result['imported']);
        $this->assertSame(0, LimoReceipt::query()->count());
    }
}
