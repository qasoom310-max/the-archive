<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\BookingForm;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoReceipt;
use Modules\Limousine\Services\LimoInvoicePdf;
use Modules\Limousine\Services\LimoReceiptPdf;
use Modules\Rental\Livewire\OrderForm;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\RentalReceipt;
use Modules\Rental\Models\Vehicle;
use Modules\Rental\Services\RentalInvoicePdf;
use Modules\Rental\Services\RentalReceiptPdf;
use Tests\TestCase;

/**
 * A company customer's VAT number, typed on the limousine booking or the
 * rental order, printed on that job's invoice and receipts.
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

    public function test_a_limousine_booking_saves_the_vat_number(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Braxtone', 'type' => 'company', 'phone' => '17000000']);

        Livewire::test(BookingForm::class)
            ->set('customer_id', $customer->id)
            ->set('pax_name', 'Helen Friberg')
            ->set('requested_by', 'Office')
            ->set('company_vat_number', ' 220015215500002 ')
            ->set('legs.0.from_location', 'Hotel')
            ->set('legs.0.to_location', 'Airport')
            ->set('legs.0.estimated_hours', '2')
            ->set('legs.0.start_at', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('legs.0.car_details', 'Sedan')
            ->set('legs.0.rate', '20')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('220015215500002', LimoBooking::query()->sole()->company_vat_number);
    }

    public function test_picking_the_customer_again_fills_it_in_from_their_last_booking(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Braxtone', 'type' => 'company']);
        LimoBooking::query()->create(['customer_id' => $customer->id, 'status' => LimoBooking::STATUS_QUEUE, 'company_vat_number' => '220015215500002']);

        Livewire::test(BookingForm::class)
            ->set('customer_id', $customer->id)
            ->assertSet('company_vat_number', '220015215500002');
    }

    public function test_the_limousine_invoice_and_receipt_print_it(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Braxtone', 'type' => 'company']);
        $booking = LimoBooking::query()->create(['customer_id' => $customer->id, 'status' => LimoBooking::STATUS_QUEUE, 'company_vat_number' => '220015215500002']);
        $invoice = LimoInvoice::query()->create(['booking_id' => $booking->id, 'customer_id' => $customer->id, 'issue_date' => now(), 'total' => 20]);
        $receipt = LimoReceipt::query()->create(['customer_id' => $customer->id, 'invoice_id' => $invoice->id, 'date' => now(), 'amount' => 20, 'method' => 'cash']);

        $this->assertSame('220015215500002', app(LimoInvoicePdf::class)->viewData($invoice)['customerVat']);
        $this->assertSame('220015215500002', app(LimoReceiptPdf::class)->viewData($receipt)['customerVat']);

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

    public function test_a_rental_order_carries_it_to_the_invoice_and_receipt(): void
    {
        $customer = RentalCustomer::query()->create(['name' => 'Braxtone', 'phone' => '17000000']);
        $vehicle = Vehicle::query()->create(['name' => 'Ford Expedition', 'plate_no' => '278003', 'daily_rate' => 50]);
        $order = RentalOrder::query()->create([
            'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'start_date' => '2026-02-20', 'end_date' => '2026-02-21', 'rate_type' => 'daily', 'rate' => 50,
            'company_vat_number' => '220015215500002',
        ]);
        $order->recalcTotals();
        $order->save();
        $invoice = $order->createInvoice();
        $receipt = RentalReceipt::query()->create([
            'customer_id' => $customer->id, 'invoice_id' => $invoice->id, 'date' => '2026-02-21', 'amount' => 50, 'method' => 'cash',
        ])->fresh();

        $this->assertSame('220015215500002', app(RentalInvoicePdf::class)->viewData($invoice)['customerVat']);
        $this->assertNotNull($receipt);
        $this->assertSame('220015215500002', app(RentalReceiptPdf::class)->viewData($receipt)['customerVat']);

        $html = view('rental::invoice-pdf', app(RentalInvoicePdf::class)->viewData($invoice))->render();
        $this->assertStringContainsString('VAT No.: 220015215500002', $html);
        $html = view('rental::receipt-pdf', app(RentalReceiptPdf::class)->viewData($receipt))->render();
        $this->assertStringContainsString('VAT No.: 220015215500002', $html);
    }

    public function test_the_rental_order_form_shows_it_and_fills_it_from_the_last_order(): void
    {
        $customer = RentalCustomer::query()->create(['name' => 'Braxtone']);
        RentalOrder::query()->create([
            'customer_id' => $customer->id, 'start_date' => '2026-02-20', 'end_date' => '2026-02-21',
            'rate_type' => 'daily', 'rate' => 50, 'company_vat_number' => '220015215500002',
        ]);

        Livewire::test(OrderForm::class)
            ->assertSee('Company VAT No.')
            ->set('customer_id', $customer->id)
            ->assertSet('company_vat_number', '220015215500002');
    }
}
