<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Limousine\Livewire\BookingForm;
use Modules\Limousine\Livewire\QuotationForm;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoExtra;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoQuotation;
use Modules\Limousine\Services\QuotationPdf;
use Tests\TestCase;

/**
 * The "final deal" box on a trip: the price agreed with the customer replaces
 * the total worked out from the rate, and is kept as a discount so the bill,
 * the invoice and the quotation all add up to it on their own.
 */
final class LimoFinalDealTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    /** A 12-hour chauffeur job at 55 an hour: 660 worked out. */
    private function form(): Testable
    {
        $customer = LimoCustomer::query()->create(['name' => 'Demo Guest', 'phone' => '39000000']);

        return Livewire::test(BookingForm::class)
            ->set('customer_id', $customer->id)
            ->set('pax_name', 'Demo Guest')
            ->set('requested_by', 'Office')
            ->set('legs.0.service_type', LimoLeg::TYPE_CHAUFFEUR)
            ->set('legs.0.from_location', 'Manama')
            ->set('legs.0.start_at', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('legs.0.hours', '12')
            ->set('legs.0.days', '1')
            ->set('legs.0.car_details', 'Sedan')
            ->set('legs.0.rate', '55');
    }

    public function test_the_final_deal_replaces_the_worked_out_total(): void
    {
        $component = $this->form();
        $this->assertSame(660.0, $component->instance()->grandTotal());

        $component->set('legs.0.final_amount', '600')
            ->assertSeeHtml('data-final-deal')
            ->assertSeeHtml('data-deal-discount');
        $this->assertSame(600.0, $component->instance()->grandTotal());

        $component->call('save')->assertHasNoErrors();

        $leg = LimoLeg::query()->sole();
        $this->assertSame(600.0, $leg->final_amount);
        $this->assertSame(600.0, $leg->net_amount);
        $this->assertSame(60.0, $leg->discount);
        $this->assertSame(660.0, $leg->line_total);

        $booking = LimoBooking::query()->sole();
        $this->assertSame(600.0, (float) $booking->fare);
        $this->assertSame(600.0, (float) $booking->syncInvoice()->total);
    }

    public function test_vat_and_extras_are_inside_the_deal(): void
    {
        $seat = LimoExtra::query()->where('name->en', 'Child seat')->sole();

        $this->form()
            ->set('legs.0.vat', '30')
            ->call('addLegExtra', 0)
            ->set('legExtras.0.0.extra', (string) $seat->id)
            ->set('legs.0.final_amount', '650')
            ->call('save')->assertHasNoErrors();

        $leg = LimoLeg::query()->sole();
        // 660 + 30 VAT + 5 extra = 695 worked out; the deal is 45 less.
        $this->assertSame(650.0, $leg->net_amount);
        $this->assertSame(45.0, $leg->discount);
        $this->assertSame(650.0, round($leg->line_total - $leg->discount + $leg->vat + $leg->extras_total, 3));
    }

    public function test_a_deal_above_the_worked_out_price_is_refused(): void
    {
        $this->form()
            ->set('legs.0.final_amount', '700')
            ->call('save')
            ->assertHasErrors(['legs.0.final_amount']);

        $this->assertSame(0, LimoLeg::query()->count());
    }

    public function test_a_deal_below_the_vat_is_refused(): void
    {
        $this->form()
            ->set('legs.0.vat', '30')
            ->set('legs.0.final_amount', '20')
            ->call('save')
            ->assertHasErrors(['legs.0.final_amount']);
    }

    public function test_clearing_the_deal_goes_back_to_the_typed_discount(): void
    {
        $this->form()->set('legs.0.final_amount', '600')->call('save')->assertHasNoErrors();
        $booking = LimoBooking::query()->sole();

        $component = Livewire::test(BookingForm::class, ['id' => $booking->id])
            ->assertSet('legs.0.final_amount', '600');

        $component->set('legs.0.final_amount', '')
            ->set('legs.0.discount', '10')
            ->call('save')->assertHasNoErrors();

        $leg = LimoLeg::query()->sole();
        $this->assertNull($leg->final_amount);
        $this->assertSame(10.0, $leg->discount);
        $this->assertSame(650.0, $leg->net_amount);
        $this->assertSame(650.0, (float) $booking->refresh()->fare);
    }

    public function test_a_pick_and_drop_trip_has_no_final_deal(): void
    {
        Livewire::test(BookingForm::class)
            ->assertSet('legs.0.service_type', LimoLeg::TYPE_TRANSFER)
            ->assertDontSeeHtml('data-final-deal');

        // A deal typed on a chauffeur trip that is then switched to pick & drop
        // is ignored: the trip goes back to its rate and typed discount.
        $component = $this->form()
            ->set('legs.0.final_amount', '600')
            ->set('legs.0.service_type', LimoLeg::TYPE_TRANSFER)
            ->set('legs.0.to_location', 'Airport')
            ->set('legs.0.estimated_hours', '1')
            ->set('legs.0.discount', '5')
            ->assertDontSeeHtml('data-final-deal')
            ->assertDontSeeHtml('data-deal-discount');
        $this->assertSame(50.0, $component->instance()->grandTotal());

        $component->call('save')->assertHasNoErrors();

        $leg = LimoLeg::query()->sole();
        $this->assertNull($leg->final_amount);
        $this->assertSame(50.0, $leg->net_amount);
    }

    public function test_a_quotation_prints_the_deal_as_its_total(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Demo Guest', 'phone' => '39000000']);

        Livewire::test(QuotationForm::class)
            ->set('customer_id', $customer->id)
            ->set('requested_by', 'Office')
            ->set('legs.0.service_type', LimoLeg::TYPE_CHAUFFEUR)
            ->set('legs.0.from_location', 'Manama')
            ->set('legs.0.start_at', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('legs.0.hours', '12')
            ->set('legs.0.days', '1')
            ->set('legs.0.rate', '55')
            ->set('legs.0.final_amount', '600')
            ->call('save')->assertHasNoErrors();

        $data = app(QuotationPdf::class)->viewData(LimoQuotation::query()->sole());
        $this->assertSame(660.0, $data['subtotal']);
        $this->assertSame(60.0, $data['discount']);
        $this->assertSame(600.0, $data['total']);
    }
}
