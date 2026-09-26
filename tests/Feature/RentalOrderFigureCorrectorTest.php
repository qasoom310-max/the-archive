<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Support\OrderFigureCorrector;
use Tests\TestCase;

/**
 * Orders the historical import brought over at 0.00 (and so read PAID) get
 * the previous system's figures back from its "Active Orders" export.
 */
final class RentalOrderFigureCorrectorTest extends TestCase
{
    use DatabaseMigrations;

    private string $csv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');

        // The old system's rows, exactly as its Active Orders list shows them.
        $this->csv = tempnam(sys_get_temp_dir(), 'ra') . '.csv';
        file_put_contents($this->csv, "\u{FEFF}Sl,RA#,Customer,Vehicle,Hire Period,Amount,VAT,Total,Receipt,Balance,Extra,Deposit,Added / Updated,Repl\n"
            . "1,RA1815,Radu Mihai Carlig,239256 - FORD ECOSPORT,31-Aug-26 to 17-Sep-26,119.000,11.900,130.900,130.900,0.000,10.000,50.000,Hashim /,\n"
            . "2,RA1794,Abdulla Hameed,239142 - FORD ECOSPORT,01-Apr-26 to 31-Mar-28,\"1,818.187\",181.819,\"2,000.006\",250.000,\"1,750.006\",0.000,0.000,via /,Yes\n"
            . "3,RA1623,Extentsa Projects,239260 - FORD ECOSPORT,05-Nov-24 to 29-Jan-26,\"3,545.460\",354.546,\"3,900.006\",\"3,860.000\",40.006,0.000,0.000,via /,\n"
            . "4,RA1613,Reham Makhlooq,202964 - FORD ECOSPORT,14-Oct-24 to 05-Oct-27,\"3,455.471\",345.547,\"3,801.018\",\"2,465.000\",\"1,336.018\",0.000,0.000,via / via,\n");
    }

    private function brokenOrder(string $reference, string $start, string $end, string $state = RentalOrder::STATE_ACTIVE): RentalOrder
    {
        $customer = RentalCustomer::query()->create(['name' => 'Customer ' . $reference]);
        $order = RentalOrder::query()->create([
            'reference' => $reference, 'customer_id' => $customer->id,
            'start_date' => $start, 'end_date' => $end, 'rate_type' => 'daily', 'rate' => 0,
            'state' => $state, 'total' => 0, 'subtotal' => 0, 'advance_amount' => 0, 'balance' => 0,
            'payment_status' => RentalOrder::PAYMENT_PAID,
        ]);
        $order->saveQuietly();

        return $order;
    }

    public function test_the_old_figures_are_restored_and_the_payment_follows_the_balance(): void
    {
        $this->brokenOrder('RA1794', '2026-04-01 13:11', '2028-03-31 13:11');
        $this->brokenOrder('RA1613', '2024-10-14 10:04', '2027-10-05 10:04');

        $r = app(OrderFigureCorrector::class)->correct($this->csv);

        $this->assertSame(2, $r['updated']);
        $this->assertSame(['RA1815', 'RA1623'], $r['missing']);

        $o = RentalOrder::query()->where('reference', 'RA1794')->firstOrFail();
        $this->assertEqualsWithDelta(1818.187, $o->subtotal, 0.0005);
        $this->assertEqualsWithDelta(181.819, $o->vat_amount, 0.0005);
        $this->assertEqualsWithDelta(2000.006, $o->total, 0.0005);
        $this->assertEqualsWithDelta(250.0, $o->advance_amount, 0.0005);
        $this->assertEqualsWithDelta(1750.006, $o->balance, 0.0005);
        $this->assertSame(RentalOrder::PAYMENT_PARTIAL, $o->payment_status);

        // Returning the car re-prices from the rate — it must not fall back to 0.
        $o->recalcTotals();
        $this->assertEqualsWithDelta(2000.006, $o->total, 0.5);
        $this->assertEqualsWithDelta(10.0, $o->vat_rate, 0.01);
    }

    public function test_a_dry_run_saves_nothing(): void
    {
        $this->brokenOrder('RA1794', '2026-04-01', '2028-03-31');

        $r = app(OrderFigureCorrector::class)->correct($this->csv, pretend: true);

        $this->assertSame(1, $r['updated']);
        $this->assertEqualsWithDelta(0.0, RentalOrder::query()->where('reference', 'RA1794')->firstOrFail()->total, 0.0005);
    }

    public function test_sync_active_matches_the_old_active_list(): void
    {
        $this->brokenOrder('RA1815', '2026-08-31', '2026-09-17', RentalOrder::STATE_CLOSED);
        $this->brokenOrder('RA1616', '2024-10-19', '2027-06-06');

        $r = app(OrderFigureCorrector::class)->correct($this->csv, syncActive: true);

        $this->assertSame(RentalOrder::STATE_ACTIVE, RentalOrder::query()->where('reference', 'RA1815')->value('state'));
        $this->assertSame(RentalOrder::STATE_CLOSED, RentalOrder::query()->where('reference', 'RA1616')->value('state'));
        $this->assertSame(['RA1616'], $r['not_in_file']);
        $this->assertSame(RentalOrder::PAYMENT_PAID, RentalOrder::query()->where('reference', 'RA1815')->value('payment_status'));
    }
}
