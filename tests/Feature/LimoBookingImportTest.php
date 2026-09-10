<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Limousine\Http\Controllers\LimoBookingImportController;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoReceipt;
use Modules\Limousine\Support\BookingImporter;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Importing a trips CSV (an export from a previous system, the same shape
 * the queue's own export already prints) into the booking queue — a row
 * becomes a booking with one leg, and lands as SETTLED HISTORY: its own
 * invoice built at the old system's figure directly, never through
 * syncInvoice()'s live pricing.
 */
final class LimoBookingImportTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private function csv(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bkg') . '.csv';
        file_put_contents(
            $path,
            "Reference,From date,To date,Type,Customer,Amount,Received,Pickup,Drop off,Vehicle,Driver,Company reference,Pax name,Status,Payment\n" . $body,
        );

        return $path;
    }

    public function test_a_fully_paid_historical_trip_lands_settled_with_a_confirmed_receipt(): void
    {
        $path = $this->csv("BK/00099,2026-06-10 09:00,,Transfer,Dadabhai Travel,45,45,Airport,Hotel,GMC Yukon,Ali,PO-1,Helen,Completed,Paid\n");

        $result = app(BookingImporter::class)->import($path);
        $this->assertSame(1, $result['imported']);

        $booking = LimoBooking::query()->firstOrFail();
        $this->assertSame('Dadabhai Travel', $booking->customer->name);
        $this->assertSame('Helen', $booking->pax_name);
        $this->assertSame('PO-1', $booking->company_reference);
        $this->assertEqualsWithDelta(45.0, $booking->fare, 0.001);
        $this->assertEqualsWithDelta(45.0, $booking->advance, 0.001);
        $this->assertSame(LimoBooking::STATUS_COMPLETED, $booking->status);
        $this->assertSame(LimoBooking::PAYMENT_PAID, $booking->payment_status);

        $leg = LimoLeg::query()->firstOrFail();
        $this->assertSame('Airport', $leg->from_location);
        $this->assertSame('Hotel', $leg->to_location);
        $this->assertSame('GMC Yukon', $leg->vehicle);

        // Settled history — an invoice at the old system's own figure, not one
        // re-derived through today's live pricing.
        $invoice = LimoInvoice::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame(LimoInvoice::STATUS_PAID, $invoice->status);
        $this->assertEqualsWithDelta(45.0, $invoice->total, 0.001);

        // Nothing left to check against a bank statement for a years-old trip
        // — it starts confirmed rather than sitting in the accountant's queue.
        $receipt = LimoReceipt::query()->firstOrFail();
        $this->assertTrue($receipt->isConfirmed());
    }

    public function test_a_part_paid_trip_lands_partial_with_no_invented_overpayment(): void
    {
        $path = $this->csv("BK/00100,2026-06-11 09:00,,Transfer,Gulf Hotel,100,40,,,, ,,,Completed,Partial\n");

        app(BookingImporter::class)->import($path);

        $invoice = LimoInvoice::query()->firstOrFail();
        $this->assertSame(LimoInvoice::STATUS_PARTIAL, $invoice->status);
        $this->assertEqualsWithDelta(40.0, $invoice->amount_paid, 0.001);
    }

    public function test_a_blank_customer_or_amount_row_is_skipped_not_crashed(): void
    {
        $path = $this->csv(",2026-06-11,,,,,,,,,,,,,\n,2026-06-11,,,No Amount,,,,,,,,,,\n");

        $result = app(BookingImporter::class)->import($path);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(0, LimoBooking::query()->count());
    }

    public function test_re_importing_the_same_file_does_not_duplicate_the_trip(): void
    {
        $path = $this->csv("BK/00099,2026-06-10 09:00,,Transfer,Dadabhai Travel,45,45,Airport,Hotel,,,,,,Paid\n");

        app(BookingImporter::class)->import($path);
        $second = app(BookingImporter::class)->import($this->csv("BK/00099,2026-06-10 09:00,,Transfer,Dadabhai Travel,45,45,Airport,Hotel,,,,,,Paid\n"));

        $this->assertSame(0, $second['imported']);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(1, LimoBooking::query()->count());
    }

    public function test_an_existing_customer_is_reused_not_duplicated(): void
    {
        $existing = LimoCustomer::query()->create(['name' => 'Dadabhai Travel', 'type' => 'company']);
        $path = $this->csv("BK/00099,2026-06-10 09:00,,Transfer,Dadabhai Travel,45,0,,,,,,,,\n");

        app(BookingImporter::class)->import($path);

        $this->assertSame(1, LimoCustomer::query()->count());
        $this->assertSame($existing->id, LimoBooking::query()->firstOrFail()->customer_id);
    }

    public function test_the_import_route_is_manager_gated(): void
    {
        $this->actingAs(User::factory()->create()); // plain staff
        $controller = new LimoBookingImportController();

        $file = new \Illuminate\Http\UploadedFile($this->csv("X,2026-06-10,,,X,45,0,,,,,,,,\n"), 'b.csv', 'text/csv', null, true);
        $request = \Illuminate\Http\Request::create('/app/limousine/booking/import', 'POST', [], [], ['file' => $file]);

        try {
            $controller($request, app(BookingImporter::class));
            $this->fail('A non-manager should be forbidden.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, LimoBooking::query()->count());
    }
}
