<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Rental\Http\Controllers\RentalOrderImportController;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;
use Modules\Rental\Support\OrderImporter;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Importing an orders CSV (an export from a previous system, the same shape
 * this screen's own export already prints) — a rental order carries its own
 * billing figures directly, so a row lands as a complete historical record
 * with no separate invoice document needed.
 */
final class RentalOrderImportTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');
    }

    private function csv(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ord') . '.csv';
        file_put_contents($path, "Reference,Customer,Car,Pick-up,Return,Total,Received,Status,Payment\n" . $body);

        return $path;
    }

    public function test_a_fully_paid_historical_order_lands_settled(): void
    {
        $path = $this->csv("RO/00099,Qassim,Eco Sport,2026-06-10,2026-06-12,50,50,Closed,Paid\n");

        $result = app(OrderImporter::class)->import($path);
        $this->assertSame(1, $result['imported']);

        $order = RentalOrder::query()->firstOrFail();
        $this->assertSame('Qassim', $order->customer->name);
        $this->assertEqualsWithDelta(50.0, $order->total, 0.001);
        $this->assertEqualsWithDelta(50.0, $order->advance_amount, 0.001);
        $this->assertEqualsWithDelta(0.0, $order->balance, 0.001);
        $this->assertSame(RentalOrder::STATE_CLOSED, $order->state);
        $this->assertSame(RentalOrder::PAYMENT_PAID, $order->payment_status);
    }

    public function test_a_matching_fleet_vehicle_is_linked_by_name(): void
    {
        $car = Vehicle::query()->create(['name' => 'Toyota Yaris', 'daily_rate' => 10]);
        $path = $this->csv("RO/00100,Qassim,Yaris,2026-06-10,,20,0,Active,Unpaid\n");

        app(OrderImporter::class)->import($path);

        $this->assertSame($car->id, RentalOrder::query()->firstOrFail()->vehicle_id);
    }

    public function test_an_unmatched_car_name_leaves_the_order_car_less_rather_than_inventing_a_fleet_entry(): void
    {
        $path = $this->csv("RO/00101,Qassim,Some Old Car,2026-06-10,,20,0,Active,Unpaid\n");

        app(OrderImporter::class)->import($path);

        $this->assertNull(RentalOrder::query()->firstOrFail()->vehicle_id);
        $this->assertSame(0, Vehicle::query()->count());
    }

    public function test_a_part_paid_order_lands_partial(): void
    {
        $path = $this->csv("RO/00102,Qassim,,2026-06-10,,100,40,Closed,Partial\n");

        app(OrderImporter::class)->import($path);

        $order = RentalOrder::query()->firstOrFail();
        $this->assertSame(RentalOrder::PAYMENT_PARTIAL, $order->payment_status);
        $this->assertEqualsWithDelta(60.0, $order->balance, 0.001);
    }

    public function test_re_importing_the_same_file_does_not_duplicate_the_order(): void
    {
        app(OrderImporter::class)->import($this->csv("RO/00099,Qassim,,2026-06-10,,50,50,Closed,Paid\n"));
        $second = app(OrderImporter::class)->import($this->csv("RO/00099,Qassim,,2026-06-10,,50,50,Closed,Paid\n"));

        $this->assertSame(0, $second['imported']);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(1, RentalOrder::query()->count());
    }

    public function test_an_existing_customer_is_reused_not_duplicated(): void
    {
        $existing = RentalCustomer::query()->create(['name' => 'Qassim', 'phone' => '39000001']);
        app(OrderImporter::class)->import($this->csv("RO/00099,Qassim,,2026-06-10,,50,0,Active,Unpaid\n"));

        $this->assertSame(1, RentalCustomer::query()->count());
        $this->assertSame($existing->id, RentalOrder::query()->firstOrFail()->customer_id);
    }

    /** The old system's "Active Orders" export, exactly as it downloads. */
    private function oldSystemCsv(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ord') . '.csv';
        file_put_contents($path, '"Sl","RA#","Customer","Vehicle","Hire Period","Amount","VAT","Total","Receipt","Balance","Extra","Deposit","Added / Updated","Repl","Actions"' . "\n"
            . '"1","RA1815","Radu Mihai Carlig , 861174844 , +40732965400","239256 - FORD ECOSPORT","31-Aug-26 12:42 to 17-Sep-26","119.000","11.900","130.900","130.900","0.000","10.000","50.000","Hashim /","","                                                               -->    "' . "\n"
            . '"2","RA1794","Abdulla Hameed Mulla Abdulla Husain Ahmed , 890902070 , 66609600","239142 - FORD ECOSPORT","01-Apr-26 13:11 to 31-Mar-28","1,818.187","181.819","2,000.006","250.000","1,750.006","0.000","0.000","via /","Yes","                                                               -->    "' . "\n"
            . '"3","RA1623","Extentsa Projects Managements , 175381-1 , +97338079481","239260 - FORD ECOSPORT","05-Nov-24 12:57 to 29-Jan-26","3,545.460","354.546","3,900.006","3,860.000","40.006","0.000","0.000","via /","","                                                               -->    "' . "\n");

        return $path;
    }

    public function test_the_old_systems_active_orders_export_imports(): void
    {
        $car = Vehicle::query()->create(['name' => 'Ford Ecosport', 'plate_no' => '239142', 'daily_rate' => 10]);
        $existing = RentalCustomer::query()->create(['name' => 'Abdulla H.', 'cpr' => '890902070']);

        $result = app(OrderImporter::class)->import($this->oldSystemCsv());
        $this->assertSame(['imported' => 3, 'skipped' => 0, 'failed' => 0], $result);

        $radu = RentalOrder::query()->where('reference', 'RA1815')->firstOrFail();
        $this->assertSame('Radu Mihai Carlig', $radu->customer?->name);
        $this->assertSame('861174844', $radu->customer?->cpr);
        $this->assertSame('+40732965400', $radu->customer?->phone);
        $this->assertSame('2026-08-31', $radu->start_date?->toDateString());
        $this->assertSame('2026-09-17', $radu->end_date?->toDateString());
        $this->assertSame('12:42', $radu->hired_time);
        $this->assertEqualsWithDelta(119.0, $radu->subtotal, 0.001);
        $this->assertEqualsWithDelta(11.9, $radu->vat_amount, 0.001);
        $this->assertEqualsWithDelta(130.9, $radu->total, 0.001);
        $this->assertEqualsWithDelta(50.0, $radu->deposit, 0.001);
        $this->assertSame(RentalOrder::PAYMENT_PAID, $radu->payment_status);

        $abdulla = RentalOrder::query()->where('reference', 'RA1794')->firstOrFail();
        $this->assertSame($existing->id, $abdulla->customer_id, 'Matched by CPR.');
        $this->assertSame($car->id, $abdulla->vehicle_id, 'Matched by plate.');
        $this->assertEqualsWithDelta(2000.006, $abdulla->total, 0.001);
        $this->assertEqualsWithDelta(1750.006, $abdulla->balance, 0.001);
        $this->assertSame(RentalOrder::PAYMENT_PARTIAL, $abdulla->payment_status);
        $this->assertSame(RentalOrder::STATE_ACTIVE, $abdulla->state);

        // Overdue but still owing: kept active.
        $this->assertSame(RentalOrder::STATE_ACTIVE, RentalOrder::query()->where('reference', 'RA1623')->firstOrFail()->state);
    }

    public function test_an_ra_number_already_on_file_is_skipped_not_rewritten(): void
    {
        app(OrderImporter::class)->import($this->oldSystemCsv());
        RentalOrder::query()->where('reference', 'RA1815')->update(['advance_amount' => 99]);

        $second = app(OrderImporter::class)->import($this->oldSystemCsv());

        $this->assertSame(['imported' => 0, 'skipped' => 3, 'failed' => 0], $second);
        $this->assertSame(3, RentalOrder::query()->count());
        $this->assertEqualsWithDelta(99.0, RentalOrder::query()->where('reference', 'RA1815')->value('advance_amount'), 0.001);
    }

    public function test_the_upload_of_the_old_export_redirects_instead_of_erroring(): void
    {
        $file = new \Illuminate\Http\UploadedFile($this->oldSystemCsv(), 'Wanaan Car Rental.csv', 'text/csv', null, true);
        $request = \Illuminate\Http\Request::create('/app/rental/order/import', 'POST', [], [], ['file' => $file]);

        $response = (new RentalOrderImportController())($request, app(OrderImporter::class));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(3, RentalOrder::query()->count());
    }

    public function test_the_import_route_is_manager_gated(): void
    {
        $this->actingAs(User::factory()->create()); // plain staff
        $controller = new RentalOrderImportController();

        $file = new \Illuminate\Http\UploadedFile($this->csv("X,X,,2026-06-10,,50,0,Active,Unpaid\n"), 'o.csv', 'text/csv', null, true);
        $request = \Illuminate\Http\Request::create('/app/rental/order/import', 'POST', [], [], ['file' => $file]);

        try {
            $controller($request, app(OrderImporter::class));
            $this->fail('A non-manager should be forbidden.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, RentalOrder::query()->count());
    }
}
