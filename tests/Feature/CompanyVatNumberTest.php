<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoReceipt;
use Modules\Limousine\Services\LimoInvoicePdf;
use Modules\Limousine\Services\LimoReceiptPdf;
use Modules\Rental\Livewire\CustomerForm;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\RentalReceipt;
use Modules\Rental\Models\Vehicle;
use Modules\Rental\Services\RentalInvoicePdf;
use Modules\Rental\Services\RentalReceiptPdf;
use Tests\TestCase;

/**
 * A company customer's VAT number, kept on the customer and printed on its
 * limousine and rental invoices and receipts.
 */
final class CompanyVatNumberTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true, 'name' => 'Qassim']));
        app(ModuleManager::class)->install('limousine');
        app(ModuleManager::class)->install('rental');
    }

    public function test_a_company_customer_saves_its_vat_number(): void
    {
        $customer = RentalCustomer::query()->create(['name' => 'Braxtone', 'type' => RentalCustomer::TYPE_COMPANY]);

        Livewire::test(CustomerForm::class, ['id' => $customer->id])
            ->assertSee('Company VAT No.')
            ->set('vat_number', ' 220015215500002 ')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('220015215500002', $customer->refresh()->vat_number);
    }

    public function test_an_individual_keeps_no_vat_number(): void
    {
        $customer = RentalCustomer::query()->create(['name' => 'Helen Friberg', 'type' => RentalCustomer::TYPE_INDIVIDUAL]);

        Livewire::test(CustomerForm::class, ['id' => $customer->id])
            ->assertDontSee('Company VAT No.')
            ->set('vat_number', '123')
            ->call('save');

        $this->assertNull($customer->refresh()->vat_number);
    }

    public function test_the_limousine_invoice_and_receipt_print_it(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Braxtone', 'type' => 'company', 'vat_number' => '220015215500002']);
        $booking = LimoBooking::query()->create(['customer_id' => $customer->id, 'status' => LimoBooking::STATUS_QUEUE]);
        $invoice = LimoInvoice::query()->create(['booking_id' => $booking->id, 'customer_id' => $customer->id, 'issue_date' => now(), 'total' => 20]);
        $receipt = LimoReceipt::query()->create(['customer_id' => $customer->id, 'invoice_id' => $invoice->id, 'date' => now(), 'amount' => 20, 'method' => 'cash']);

        $html = view('limousine::invoice-pdf', app(LimoInvoicePdf::class)->viewData($invoice))->render();
        $this->assertStringContainsString('VAT No.: 220015215500002', $html);
        $html = view('limousine::receipt-pdf', app(LimoReceiptPdf::class)->viewData($receipt))->render();
        $this->assertStringContainsString('VAT No.: 220015215500002', $html);
    }

    public function test_no_vat_number_prints_no_line(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Helen Friberg']);
        $booking = LimoBooking::query()->create(['customer_id' => $customer->id, 'status' => LimoBooking::STATUS_QUEUE]);
        $invoice = LimoInvoice::query()->create(['booking_id' => $booking->id, 'customer_id' => $customer->id, 'issue_date' => now(), 'total' => 20]);

        $html = view('limousine::invoice-pdf', app(LimoInvoicePdf::class)->viewData($invoice))->render();
        $this->assertStringNotContainsString('VAT No.:', $html);
    }

    public function test_the_rental_invoice_and_receipt_print_it(): void
    {
        $customer = RentalCustomer::query()->create(['name' => 'Braxtone', 'type' => RentalCustomer::TYPE_COMPANY, 'vat_number' => '220015215500002']);
        $vehicle = Vehicle::query()->create(['name' => 'Ford Expedition', 'plate_no' => '278003', 'daily_rate' => 50]);
        $order = RentalOrder::query()->create([
            'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'start_date' => '2026-02-20', 'end_date' => '2026-02-21', 'rate_type' => 'daily', 'rate' => 50,
        ]);
        $order->recalcTotals();
        $order->save();
        $invoice = $order->createInvoice();
        $receipt = RentalReceipt::query()->create([
            'customer_id' => $customer->id, 'invoice_id' => $invoice->id, 'date' => '2026-02-21', 'amount' => 50, 'method' => 'cash',
        ])->fresh();
        $this->assertNotNull($receipt);

        $html = view('rental::invoice-pdf', app(RentalInvoicePdf::class)->viewData($invoice))->render();
        $this->assertStringContainsString('VAT No.: 220015215500002', $html);
        $html = view('rental::receipt-pdf', app(RentalReceiptPdf::class)->viewData($receipt))->render();
        $this->assertStringContainsString('VAT No.: 220015215500002', $html);
    }

    /** A number typed on a booking the morning it lived there moves to its customer. */
    public function test_numbers_typed_on_bookings_and_orders_move_to_their_customer(): void
    {
        $migration = require base_path('Modules/Rental/database/migrations/2026_10_21_900053_move_company_vat_number_to_customers.php');
        $migration->down();
        foreach (['limo_bookings', 'rental_orders'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->string('company_vat_number', 50)->nullable());
        }

        $braxtone = RentalCustomer::query()->create(['name' => 'Braxtone', 'type' => RentalCustomer::TYPE_COMPANY]);
        $kept = RentalCustomer::query()->create(['name' => 'Gulf Air', 'type' => RentalCustomer::TYPE_COMPANY]);
        DB::table('limo_bookings')->insert(['customer_id' => $braxtone->id, 'status' => 'queue', 'company_vat_number' => '220015215500002', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('rental_orders')->insert(['customer_id' => $kept->id, 'start_date' => '2026-02-20', 'end_date' => '2026-02-21', 'company_vat_number' => '300000000000003', 'created_at' => now(), 'updated_at' => now()]);

        $migration->up();

        $this->assertSame('220015215500002', $braxtone->refresh()->vat_number);
        $this->assertSame('300000000000003', $kept->refresh()->vat_number);
        $this->assertFalse(Schema::hasColumn('limo_bookings', 'company_vat_number'));
        $this->assertFalse(Schema::hasColumn('rental_orders', 'company_vat_number'));
    }
}
