<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Support\LegacyBookingImporter;
use Tests\TestCase;

/**
 * Bringing bookings over from the previous system's own list exports: the old
 * booking number becomes the booking's id, a number already on file is never
 * touched, and the welded "Name,CPR, phonePax - phone" customer cell is pulled
 * apart into customer, passenger and phone.
 */
final class LimoLegacyBookingImportTest extends TestCase
{
    use DatabaseMigrations;

    private const ACTIVE_HEADER = '"Sl No.","#","From","To","Type","Customer","Amount","Received","Balance","Pickup","Drop off","Vehicle","Driver","Added By","Comments","Status","Booked","Actions"';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private function csv(string $header, string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lgb').'.csv';
        file_put_contents($path, $header."\n".$body);

        return $path;
    }

    private function activeRow(string $number = '15452', string $customer = 'Fursan Travel,0, +966 59 781 7502Adel - +973 3944 1093'): string
    {
        return '"1","'.$number.'","14-Sep-26 11:00","14-Sep-26 11:00","Pickup / Drop","'.$customer.'","37.000","0.000","37.000","Sar Villa 1398, https://maps.google.com/?q=26.199543,50.489735","Khobar, Extra Head office","Sedan","Habib","hassan","","Driver Assigned","13-Sep-26 11:37"," "'."\n";
    }

    public function test_an_active_booking_keeps_its_number_and_splits_the_customer_cell(): void
    {
        $fursan = LimoCustomer::query()->create(['name' => 'Fursan Travel', 'type' => 'company', 'phone' => '+966597817502', 'active' => true]);

        $result = app(LegacyBookingImporter::class)->import($this->csv(self::ACTIVE_HEADER, $this->activeRow()), LimoBooking::STATUS_ACTIVE);

        $this->assertSame(1, $result['imported']);
        $booking = LimoBooking::query()->findOrFail(15452);
        $this->assertSame('BK/15452', $booking->reference);
        $this->assertSame($fursan->id, $booking->customer_id);
        $this->assertSame('Adel', $booking->pax_name);
        $this->assertSame('+97339441093', $booking->pax_contact);
        $this->assertSame('2026-09-14 11:00', $booking->pickup_at?->format('Y-m-d H:i'));
        $this->assertSame('2026-09-13 11:37', $booking->created_at?->format('Y-m-d H:i'));
        $this->assertSame(LimoBooking::STATUS_ACTIVE, $booking->status);
        $this->assertSame(LimoBooking::PAYMENT_UNPAID, $booking->payment_status);
        $this->assertEqualsWithDelta(37.0, $booking->fare, 0.001);
        $this->assertSame('Sar Villa 1398', $booking->pickup_address);
        $this->assertSame('hassan', $booking->prepared_by);
        $this->assertSame('Sedan', $booking->car_details);

        $leg = LimoLeg::query()->where('legable_id', 15452)->sole();
        $this->assertSame('https://maps.google.com/?q=26.199543,50.489735', $leg->from_location_url);
        $this->assertSame('Khobar, Extra Head office', $leg->to_location);
        $this->assertSame('Habib', $leg->driver);
        $this->assertSame(LimoLeg::TYPE_TRANSFER, $leg->service_type);
        $this->assertSame(LimoBooking::STATUS_ACTIVE, $leg->status);

        // Invoices and receipts come over from their own exports.
        $this->assertSame(0, LimoInvoice::query()->count());
    }

    /**
     * created_at is deliberately backdated to the booking's original date, so
     * it can never answer "when did this row land in OUR database" — imported_at
     * is the column that does, and it must reflect the real import moment, not
     * the historical one.
     */
    public function test_the_import_stamps_when_the_row_actually_landed_here_separately_from_the_historical_date(): void
    {
        Carbon::setTestNow('2026-09-21 09:00:00');

        app(LegacyBookingImporter::class)->import($this->csv(self::ACTIVE_HEADER, $this->activeRow()), LimoBooking::STATUS_ACTIVE);

        $booking = LimoBooking::query()->findOrFail(15452);
        $this->assertSame('2026-09-13 11:37', $booking->created_at?->format('Y-m-d H:i'));
        $this->assertSame('2026-09-21 09:00', $booking->imported_at?->format('Y-m-d H:i'));

        Carbon::setTestNow();
    }

    /** A booking made through the ordinary ERP flow was never imported, so it carries no imported_at at all. */
    public function test_a_booking_entered_live_in_the_erp_has_no_imported_at(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Walk-in', 'active' => true]);
        $booking = LimoBooking::query()->create([
            'customer_id' => $customer->id, 'pickup_at' => now(), 'fare' => 20, 'amount' => 20,
            'status' => LimoBooking::STATUS_QUEUE, 'payment_status' => LimoBooking::PAYMENT_UNPAID,
        ]);

        $this->assertNull($booking->imported_at);
    }

    public function test_a_number_already_on_file_is_skipped_and_a_different_booking_is_reported_as_a_clash(): void
    {
        $importer = app(LegacyBookingImporter::class);
        $importer->import($this->csv(self::ACTIVE_HEADER, $this->activeRow()), LimoBooking::STATUS_ACTIVE);

        $again = $importer->import($this->csv(self::ACTIVE_HEADER, $this->activeRow().$this->activeRow('15452', 'Someone Else,, +973 3300 0000 -')), LimoBooking::STATUS_ACTIVE);

        $this->assertSame(0, $again['imported']);
        $this->assertSame(2, $again['skipped']);
        $this->assertStringStartsWith('EXISTS', $again['lines'][0]);
        $this->assertStringStartsWith('CLASH', $again['lines'][1]);
        $this->assertSame(1, LimoBooking::query()->count());
    }

    public function test_a_trip_already_keyed_in_here_under_another_number_is_not_added_twice(): void
    {
        $fursan = LimoCustomer::query()->create(['name' => 'Fursan Travel', 'active' => true]);
        LimoBooking::query()->create([
            'customer_id' => $fursan->id, 'pickup_at' => '2026-09-14 11:00:00', 'pax_name' => 'Adel',
            'fare' => 37, 'amount' => 37, 'status' => LimoBooking::STATUS_QUEUE, 'payment_status' => LimoBooking::PAYMENT_UNPAID,
        ]);

        $result = app(LegacyBookingImporter::class)->import($this->csv(self::ACTIVE_HEADER, $this->activeRow('15999')), LimoBooking::STATUS_ACTIVE);

        $this->assertSame(0, $result['imported']);
        $this->assertStringStartsWith('TWIN', $result['lines'][0]);
    }

    public function test_two_old_bookings_for_two_cars_at_the_same_time_both_come_over(): void
    {
        $customer = 'Fouad Aldossary,0, +966505862641.  -';
        $result = app(LegacyBookingImporter::class)->import($this->csv(
            self::ACTIVE_HEADER,
            $this->activeRow('15462', $customer).$this->activeRow('15463', $customer),
        ), LimoBooking::STATUS_QUEUE);

        $this->assertSame(2, $result['imported']);
    }

    public function test_a_booking_stored_a_minute_early_by_the_earlier_migration_is_the_same_booking(): void
    {
        $importer = app(LegacyBookingImporter::class);
        $importer->import($this->csv(self::ACTIVE_HEADER, $this->activeRow()), LimoBooking::STATUS_ACTIVE);
        LimoBooking::query()->whereKey(15452)->update(['pickup_at' => '2026-09-14 10:59:59']);

        $again = $importer->import($this->csv(self::ACTIVE_HEADER, $this->activeRow()), LimoBooking::STATUS_ACTIVE);

        $this->assertStringStartsWith('EXISTS', $again['lines'][0]);
    }

    public function test_two_guests_of_one_company_at_the_same_time_are_two_trips(): void
    {
        $result = app(LegacyBookingImporter::class)->import($this->csv(
            self::ACTIVE_HEADER,
            $this->activeRow('15452').$this->activeRow('15454', 'Fursan Travel,0, +966 59 781 7502Kubra - +973 3963 5392'),
        ), LimoBooking::STATUS_ACTIVE);

        $this->assertSame(2, $result['imported']);
        $this->assertSame('Kubra', LimoBooking::query()->findOrFail(15454)->pax_name);
    }

    public function test_a_dry_run_saves_nothing(): void
    {
        $result = app(LegacyBookingImporter::class)->import($this->csv(self::ACTIVE_HEADER, $this->activeRow()), LimoBooking::STATUS_ACTIVE, pretend: true);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(0, LimoBooking::query()->count());
        $this->assertSame(0, LimoCustomer::query()->count());
    }

    public function test_a_customer_is_matched_by_phone_and_otherwise_created(): void
    {
        $known = LimoCustomer::query()->create(['name' => 'City Connect', 'phone' => '+97333292090', 'active' => true]);

        app(LegacyBookingImporter::class)->import($this->csv(
            self::ACTIVE_HEADER,
            $this->activeRow('100', 'City connect general trade,. , 33292090 -').$this->activeRow('101', 'Tytyana,, +380960230434 -'),
        ), LimoBooking::STATUS_ACTIVE);

        $this->assertSame($known->id, LimoBooking::query()->findOrFail(100)->customer_id);
        $new = LimoBooking::query()->findOrFail(101)->customer;
        $this->assertSame('Tytyana', $new->name);
        $this->assertSame('+380960230434', $new->phone);
        $this->assertNull(LimoBooking::query()->findOrFail(101)->pax_name);
    }

    public function test_the_closed_list_keeps_the_booked_type_and_notes_the_plate_and_commission(): void
    {
        $header = '"Sl","#","From Date","To Date","Type","Customer","Amount","Received","Balance","Commission","Pickup","Drop off","Vehicle","Details","Driver","Added By","Requested By","Booked Time","Actions"';
        $row = '"16","15441","11-Sep-26 17:20","11-Sep-26 20:20","Chauffeur","Ruqaiya Mahmood,, +96895912777Mr.Taha L Lawati -","90.000","90.000","0.000","5.000","Bahrain airport GF507(3 hours)","Four seasons hotel","278003 FORD EXPEDITION","Lexus ES350","Prima","hassan","Wanaan","10-Sep-26 15:38"," "'."\n";

        app(LegacyBookingImporter::class)->import($this->csv($header, $row), LimoBooking::STATUS_COMPLETED);

        $booking = LimoBooking::query()->findOrFail(15441);
        $this->assertSame('Mr.Taha L Lawati', $booking->pax_name);
        $this->assertSame('Lexus ES350', $booking->car_details);
        $this->assertSame(LimoBooking::PAYMENT_PAID, $booking->payment_status);
        $this->assertSame('Wanaan', $booking->requested_by);
        $this->assertStringContainsString('Vehicle: 278003 FORD EXPEDITION', (string) $booking->notes);
        $this->assertStringContainsString('Commission: 5.000', (string) $booking->notes);

        $leg = LimoLeg::query()->where('legable_id', 15441)->sole();
        $this->assertSame(LimoLeg::TYPE_CHAUFFEUR, $leg->service_type);
        $this->assertEqualsWithDelta(3.0, (float) $leg->hours, 0.001);
    }

    private function upload(string $path, ?string $list = null): \Illuminate\Http\RedirectResponse
    {
        $request = \Illuminate\Http\Request::create('/x', 'POST', $list !== null ? ['list' => $list] : [], [], [
            'file' => new \Illuminate\Http\UploadedFile($path, 'b.csv', 'text/csv', null, true),
        ]);
        $this->app->instance('request', $request);

        return (new \Modules\Limousine\Http\Controllers\LimoBookingImportController())($request, app(\Modules\Limousine\Support\BookingImporter::class));
    }

    /** The Import button takes an old-system list and puts it in the list the uploader picked. */
    public function test_the_import_button_takes_an_old_system_list_into_the_chosen_status(): void
    {
        $unpaid = $this->upload($this->csv(self::ACTIVE_HEADER, $this->activeRow('15460', 'Someone')), 'unpaid');
        $this->assertSame(LimoBooking::STATUS_COMPLETED, LimoBooking::query()->findOrFail(15460)->status);
        $this->assertStringContainsString('1 trips imported', (string) $unpaid->getSession()?->get('toast'));

        $this->upload($this->csv(self::ACTIVE_HEADER, $this->activeRow('15461', 'Someone else')), 'confirmed');
        $this->assertSame(LimoBooking::STATUS_CONFIRMED, LimoBooking::query()->findOrFail(15461)->status);
    }

    public function test_an_old_system_list_without_a_chosen_list_is_refused_and_saves_nothing(): void
    {
        $response = $this->upload($this->csv(self::ACTIVE_HEADER, $this->activeRow()));

        $this->assertTrue($response->getSession()?->get('errors')?->has('list'));
        $this->assertSame(0, LimoBooking::query()->count());
    }

    /** This ERP's own export keeps the trip number and each row's status, and builds no invoice. */
    public function test_the_import_button_takes_the_erp_export_keeping_trip_numbers(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'erpb').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".'Reference,"From date","To date",Type,Customer,Amount,Received,Balance,Pickup,"Drop off",Vehicle,Driver,"Added by",Comments,"Booked time",Status,Payment'."\n"
            .'26215,"21-Sep-26 14:30","21-Sep-26 14:30","Airport transfer","Travel Gate",15,15,0,"BIA","Ritz",Sedan,Habib,"Hasan Makhlooq","Flight GF 12","20-Sep-26 09:05",confirmed,paid'."\n"
            .'26216,"22-Sep-26 08:00","22-Sep-26 16:00","Hourly / disposal","Mansour Mohamed",80,0,80,"Hotel",,SUV,,,,"21-Sep-26 10:00",cancelled,unpaid'."\n");

        $this->upload($path);
        $this->upload($path);

        $this->assertSame(2, LimoLeg::query()->count());
        $this->assertSame(0, LimoInvoice::query()->count());

        $leg = LimoLeg::query()->where('reference', '26215')->sole();
        $this->assertSame(26215 - LimoLeg::REFERENCE_START + 1, $leg->id);
        $booking = LimoBooking::query()->findOrFail($leg->legable_id);
        $this->assertSame(LimoBooking::STATUS_CONFIRMED, $booking->status);
        $this->assertSame(LimoBooking::PAYMENT_PAID, $booking->payment_status);
        $this->assertSame('airport', $booking->booking_type);
        $this->assertSame('Hasan Makhlooq', $booking->prepared_by);
        $this->assertSame('Flight GF 12', $booking->notes);
        $this->assertSame('2026-09-20 09:05', $booking->created_at?->format('Y-m-d H:i'));
        $this->assertNull($booking->imported_at);

        $chauffeur = LimoLeg::query()->where('reference', '26216')->sole();
        $this->assertSame(LimoLeg::TYPE_CHAUFFEUR, $chauffeur->service_type);
        $this->assertSame(LimoBooking::STATUS_CANCELLED, LimoBooking::query()->findOrFail($chauffeur->legable_id)->status);
    }
}
