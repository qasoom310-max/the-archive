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
use Modules\Limousine\Support\LegacyInvoiceImporter;
use Tests\TestCase;

/**
 * Bringing invoices over from the previous system's own invoices export: the
 * old invoice number becomes the invoice's id, a single already-on-file
 * booking links directly (and claims any receipt already recorded against it
 * with no invoice of its own), and anything else — several bookings, or the
 * one booking not yet on file — is preserved as the "Bookings: …" notes
 * convention LimoInvoicePdf already knows how to recover.
 */
final class LimoLegacyInvoiceImportTest extends TestCase
{
    use DatabaseMigrations;

    private const HEADER = '"Sl No.","Invoice #","Invoice Date","Customer Name","LPO #","Booking Ref","Amount (BHD)","Added By","Actions"';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private function csv(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lgi').'.csv';
        file_put_contents($path, self::HEADER."\n".$body);

        return $path;
    }

    private function row(string $number, string $date, string $customer, string $bookingRef, string $amount): string
    {
        return '"1","'.$number.'","'.$date.'","'.$customer.'","","'.$bookingRef.'","'.$amount.'","ali"," View-->  Print "'."\n";
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

    public function test_a_single_booking_invoice_links_directly_and_claims_an_orphan_receipt(): void
    {
        $booking = $this->booking(15431, 'City connect general trade', 72.0);
        $orphan = LimoReceipt::query()->create([
            'booking_id' => $booking->id,
            'customer_id' => $booking->customer_id,
            'date' => '2026-09-08',
            'amount' => 72.0,
            'method' => 'benefit',
        ]);

        $result = app(LegacyInvoiceImporter::class)->import($this->csv(
            $this->row('1331', '08-Sep-2026', 'City connect general trade', '15431', '72.000'),
        ));

        $this->assertSame(1, $result['imported']);
        $this->assertStringStartsWith('NEW', $result['lines'][0]);
        $this->assertStringContainsString('claimed 1 existing receipt', $result['lines'][0]);

        $invoice = LimoInvoice::query()->findOrFail(1331);
        $this->assertSame('INV/01331', $invoice->reference);
        $this->assertSame($booking->id, $invoice->booking_id);
        $this->assertSame($booking->customer_id, $invoice->customer_id);
        $this->assertEqualsWithDelta(72.0, $invoice->total, 0.001);
        $this->assertNull($invoice->notes);
        $this->assertSame('2026-09-08', $invoice->issue_date?->format('Y-m-d'));

        $this->assertSame($invoice->id, $orphan->refresh()->invoice_id);
        $this->assertEqualsWithDelta(72.0, $invoice->refresh()->amount_paid, 0.001);
        $this->assertSame(LimoInvoice::STATUS_PAID, $invoice->status);
    }

    public function test_a_combined_multi_booking_invoice_is_recorded_via_notes(): void
    {
        $result = app(LegacyInvoiceImporter::class)->import($this->csv(
            $this->row('1327', '01-Sep-2026', 'Braxtone Plus W.L.L', '15119, 15124, 15131', '851.500'),
        ));

        $this->assertSame(1, $result['imported']);
        $this->assertStringContainsString('combined invoice, bookings 15119, 15124, 15131 (3)', $result['lines'][0]);

        $invoice = LimoInvoice::query()->findOrFail(1327);
        $this->assertSame('INV/01327', $invoice->reference);
        $this->assertNull($invoice->booking_id);
        $this->assertNull($invoice->quotation_id);
        $this->assertSame('Invoice #1327 | Bookings: 15119, 15124, 15131', $invoice->notes);
        $this->assertSame('Braxtone Plus W.L.L', $invoice->customer?->name);
        $this->assertEqualsWithDelta(851.5, $invoice->total, 0.001);
    }

    public function test_a_single_booking_not_yet_on_file_falls_back_to_the_notes_convention(): void
    {
        app(LegacyInvoiceImporter::class)->import($this->csv(
            $this->row('1329', '04-Sep-2026', 'Mahad Ahmad', '15409', '10.000'),
        ));

        $invoice = LimoInvoice::query()->findOrFail(1329);
        $this->assertNull($invoice->booking_id);
        $this->assertSame('Invoice #1329 | Bookings: 15409', $invoice->notes);
        $this->assertSame('Mahad Ahmad', $invoice->customer?->name);
    }

    public function test_an_invoice_number_already_on_file_is_skipped(): void
    {
        $importer = app(LegacyInvoiceImporter::class);
        $importer->import($this->csv($this->row('1331', '08-Sep-2026', 'City connect general trade', '15431', '72.000')));

        $again = $importer->import($this->csv($this->row('1331', '08-Sep-2026', 'City connect general trade', '15431', '72.000')));

        $this->assertSame(0, $again['imported']);
        $this->assertSame(1, $again['skipped']);
        $this->assertStringStartsWith('EXISTS', $again['lines'][0]);
        $this->assertSame(1, LimoInvoice::query()->count());
    }

    public function test_amounts_with_thousand_separators_and_no_bookings_parse_correctly(): void
    {
        app(LegacyInvoiceImporter::class)->import($this->csv(
            $this->row('1256', '26-May-2026', 'Trust Services Company Ltd', '14649, 14650, 14651, 14654, 14657, 14658', '2,790.000'),
        ));

        $invoice = LimoInvoice::query()->findOrFail(1256);
        $this->assertEqualsWithDelta(2790.0, $invoice->total, 0.001);
        $this->assertSame('Invoice #1256 | Bookings: 14649, 14650, 14651, 14654, 14657, 14658', $invoice->notes);
    }

    public function test_rows_missing_invoice_number_or_customer_are_skipped(): void
    {
        $result = app(LegacyInvoiceImporter::class)->import($this->csv(
            $this->row('', '08-Sep-2026', 'Someone', '15431', '72.000')
            .$this->row('1400', '08-Sep-2026', '', '15431', '72.000'),
        ));

        $this->assertSame(0, $result['imported']);
        $this->assertSame(2, $result['skipped']);
        foreach ($result['lines'] as $line) {
            $this->assertStringStartsWith('SKIP', $line);
        }
    }

    public function test_a_dry_run_saves_nothing(): void
    {
        $result = app(LegacyInvoiceImporter::class)->import(
            $this->csv($this->row('1331', '08-Sep-2026', 'City connect general trade', '15431', '72.000')),
            pretend: true,
        );

        $this->assertSame(1, $result['imported']);
        $this->assertSame(0, LimoInvoice::query()->count());
        $this->assertSame(0, LimoCustomer::query()->count());
    }
}
