<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Rental\Http\Controllers\RentalQuotationController;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalQuotation;
use Modules\Rental\Models\Vehicle;
use Modules\Rental\Services\RentalQuotationPdf;
use Tests\TestCase;

/**
 * The rental quotation as the customer receives it — same reference-template
 * design as the Rental invoice, with the item-table columns the owner
 * pointed at on the old system's printed quotation: No. / Service / Vehicle /
 * Period (From/To) / Days / Rate / Amount, and a Subtotal/Discount/VAT/Total
 * box.
 */
final class RentalQuotationDocumentTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');
    }

    private function quotation(float $rate = 9, int $days = 7, float $discount = 0, float $deposit = 0): RentalQuotation
    {
        $customer = RentalCustomer::query()->create(['name' => 'Mercury Global Co. W.L.L', 'phone' => '39000000']);
        $vehicle = Vehicle::query()->create([
            'name' => 'Ford EcoSport', 'plate_no' => '111629', 'make' => 'FORD', 'model' => 'ECOSPORT',
            'daily_rate' => $rate,
        ]);

        $quote = RentalQuotation::query()->create([
            'reference' => 'QT0045',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'start_date' => '2023-10-17',
            'end_date' => \Illuminate\Support\Carbon::parse('2023-10-17')->addDays($days)->toDateString(),
            'valid_until' => '2023-10-24',
            'rate_type' => 'daily',
            'rate' => $rate,
            'discount' => $discount,
            'deposit' => $deposit,
        ]);
        $quote->recalcTotals();
        $quote->save();

        return $quote->fresh();
    }

    public function test_the_document_carries_the_quotations_vehicle_and_period(): void
    {
        $data = app(RentalQuotationPdf::class)->viewData($this->quotation());

        $this->assertSame('Mercury Global Co. W.L.L', $data['customerName']);
        $this->assertSame('QT0045', $data['reference']);
        $this->assertCount(1, $data['lines']);
        $this->assertSame(__('Rental'), $data['lines'][0]['service']);
        $this->assertStringContainsString('111629', $data['lines'][0]['vehicle']);
        $this->assertStringContainsString('FORD', $data['lines'][0]['vehicle']);
        $this->assertSame('17-10-2023', $data['lines'][0]['from']);
    }

    /** Rate × days reproduces the quotation's own subtotal, and VAT/total foot correctly. */
    public function test_the_figures_multiply_out_and_the_summary_foots_to_the_total(): void
    {
        $data = app(RentalQuotationPdf::class)->viewData($this->quotation(rate: 9, days: 7));

        $this->assertEqualsWithDelta(63.0, $data['lines'][0]['amount'], 0.001);
        $this->assertEqualsWithDelta(63.0, $data['subtotal'], 0.001);
        $this->assertEqualsWithDelta(0.0, $data['discount'], 0.001);
        $this->assertEqualsWithDelta(10.0, $data['vatRate'], 0.001);
        $this->assertEqualsWithDelta(6.3, $data['vatAmount'], 0.001);
        $this->assertEqualsWithDelta(69.3, $data['total'], 0.001);

        $this->assertEqualsWithDelta(
            $data['total'],
            $data['subtotal'] - $data['discount'] + $data['vatAmount'],
            0.001,
        );
    }

    public function test_a_discount_reduces_the_vat_base(): void
    {
        $data = app(RentalQuotationPdf::class)->viewData($this->quotation(rate: 100, days: 1, discount: 20));

        $this->assertEqualsWithDelta(100.0, $data['subtotal'], 0.001);
        $this->assertEqualsWithDelta(20.0, $data['discount'], 0.001);
        // VAT applies to what's actually owed after the discount, not the raw subtotal.
        $this->assertEqualsWithDelta(8.0, $data['vatAmount'], 0.001);
        $this->assertEqualsWithDelta(88.0, $data['total'], 0.001);
    }

    /** A quotation with no vehicle chosen yet still renders honestly, with no line. */
    public function test_a_quotation_with_no_vehicle_renders_with_no_lines(): void
    {
        $customer = RentalCustomer::query()->create(['name' => 'No Vehicle Yet']);
        $quote = RentalQuotation::query()->create([
            'reference' => 'QT09999',
            'customer_id' => $customer->id,
            'subtotal' => 50, 'total' => 50,
        ]);

        $data = app(RentalQuotationPdf::class)->viewData($quote);
        $this->assertSame([], $data['lines']);

        $html = view('rental::quotation-pdf', $data)->render();
        $this->assertStringContainsString(__('Rental services'), $html);
    }

    public function test_the_deposit_produces_a_requirements_note(): void
    {
        $data = app(RentalQuotationPdf::class)->viewData($this->quotation(deposit: 50));

        $html = view('rental::quotation-pdf', $data)->render();
        $this->assertStringContainsString(__('Requirements are the following'), $html);
    }

    public function test_no_deposit_means_no_requirements_note(): void
    {
        $data = app(RentalQuotationPdf::class)->viewData($this->quotation(deposit: 0));

        $html = view('rental::quotation-pdf', $data)->render();
        $this->assertStringNotContainsString(__('Requirements are the following'), $html);
    }

    public function test_the_pdf_renders_and_the_download_icon_serves_it(): void
    {
        $quote = $this->quotation();

        $this->assertStringStartsWith('%PDF-', app(RentalQuotationPdf::class)->render($quote));

        $response = (new RentalQuotationController())($quote->id, app(RentalQuotationPdf::class));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->assertStringContainsString(
            'quotation-' . str_replace(['/', '\\', ' '], '-', (string) $quote->reference) . '.pdf',
            (string) $response->headers->get('Content-Disposition'),
        );
    }
}
