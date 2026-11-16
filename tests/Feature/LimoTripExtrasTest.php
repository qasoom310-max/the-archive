<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\BookingForm;
use Modules\Limousine\Livewire\Bookings;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoExtra;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoQuotation;
use Modules\Limousine\Services\LimoInvoicePdf;
use Modules\Limousine\Services\QuotationPdf;
use Tests\TestCase;

/**
 * Extras sold with a trip (child seat, Causeway Plus, luggage helper, or
 * anything typed under "Other"): each with a count, added to the trip's price
 * and printed on the bill as its own line.
 */
final class LimoTripExtrasTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true, 'name' => 'Qassim']));
        app(ModuleManager::class)->install('limousine');
    }

    private function childSeat(): LimoExtra
    {
        return LimoExtra::query()->where('name->en', 'Child seat')->sole();
    }

    private function form(): \Livewire\Features\SupportTesting\Testable
    {
        $customer = LimoCustomer::query()->create(['name' => 'Helen Friberg', 'phone' => '39000000']);

        return Livewire::test(BookingForm::class)
            ->set('customer_id', $customer->id)
            ->set('pax_name', 'Helen Friberg')
            ->set('requested_by', 'Office')
            ->set('legs.0.from_location', 'Hotel')
            ->set('legs.0.to_location', 'Airport')
            ->set('legs.0.estimated_hours', '2')
            ->set('legs.0.start_at', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('legs.0.car_details', 'Sedan')
            ->set('legs.0.rate', '20');
    }

    /** Two child seats (5 each) and a typed "Flowers" at 7.5. */
    private function formWithExtras(): \Livewire\Features\SupportTesting\Testable
    {
        return $this->form()
            ->call('addLegExtra', 0)
            ->set('legExtras.0.0.extra', (string) $this->childSeat()->id)
            ->call('stepLegExtra', 0, 0, 1)
            ->call('addLegExtra', 0)
            ->set('legExtras.0.1.extra', 'other')
            ->set('legExtras.0.1.name', 'Flowers')
            ->set('legExtras.0.1.price', '7.5');
    }

    public function test_the_price_list_starts_with_the_three_extras(): void
    {
        $this->assertSame(
            ['Child seat' => 5.0, 'Causeway Plus' => 30.0, 'Luggage helper' => 10.0, 'Hala Bahrain' => 33.0],
            LimoExtra::query()->orderBy('sequence')->get()
                ->mapWithKeys(static fn (LimoExtra $e): array => [$e->billName() => $e->price])->all(),
        );
        $this->assertSame('كرسي أطفال', $this->childSeat()->getTranslation('name', 'ar'));
    }

    public function test_hala_bahrain_starts_at_33_and_takes_the_price_typed_for_the_trip(): void
    {
        $hala = LimoExtra::query()->where('name->en', 'Hala Bahrain')->sole();
        $this->assertTrue($hala->price_editable);
        $this->assertFalse($this->childSeat()->price_editable);

        $component = $this->form()
            ->call('addLegExtra', 0)
            ->set('legExtras.0.0.extra', (string) $hala->id)
            ->assertSet('legExtras.0.0.price', '33')
            ->assertSeeHtml('data-extra-price')
            ->assertSee('price can be changed')
            ->set('legExtras.0.0.price', '41.5');

        $component->call('save')->assertHasNoErrors();

        $leg = LimoLeg::query()->sole();
        $this->assertSame([
            ['name' => 'Hala Bahrain', 'price' => 41.5, 'qty' => 1, 'amount' => 41.5],
        ], $leg->extraLines());
        $this->assertSame(61.5, $leg->net_amount);
    }

    public function test_a_fixed_price_extra_shows_no_price_box(): void
    {
        $this->form()
            ->call('addLegExtra', 0)
            ->set('legExtras.0.0.extra', (string) $this->childSeat()->id)
            ->assertDontSeeHtml('data-extra-price');
    }

    public function test_picking_an_extra_fills_its_price_and_the_count_multiplies_it(): void
    {
        $component = $this->formWithExtras();

        $this->assertSame('5', $component->get('legExtras.0.0.price'));
        $this->assertSame('2', $component->get('legExtras.0.0.qty'));
        $this->assertSame(17.5, $component->instance()->legExtrasTotal(0));
        $this->assertSame(37.5, $component->instance()->grandTotal());
    }

    public function test_extras_are_saved_on_the_trip_and_added_to_the_fare(): void
    {
        $this->formWithExtras()->call('save')->assertHasNoErrors();

        $leg = LimoLeg::query()->sole();
        $this->assertSame(17.5, $leg->extras_total);
        $this->assertSame(37.5, $leg->net_amount);
        $this->assertSame(20.0, $leg->line_total);
        $this->assertSame([
            ['name' => 'Child seat', 'price' => 5.0, 'qty' => 2, 'amount' => 10.0],
            ['name' => 'Flowers', 'price' => 7.5, 'qty' => 1, 'amount' => 7.5],
        ], $leg->extraLines());
        $this->assertSame(37.5, (float) LimoBooking::query()->value('fare'));
    }

    public function test_a_price_change_later_does_not_rewrite_a_trip_already_sold(): void
    {
        $this->formWithExtras()->call('save')->assertHasNoErrors();
        $this->childSeat()->update(['price' => 8]);

        $booking = LimoBooking::query()->sole();
        Livewire::test(BookingForm::class, ['id' => $booking->id])
            ->assertSet('legExtras.0.0.price', '5')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(37.5, (float) LimoBooking::query()->value('fare'));
    }

    public function test_other_needs_a_name_and_a_price(): void
    {
        $this->form()
            ->call('addLegExtra', 0)
            ->set('legExtras.0.0.extra', 'other')
            ->call('save')
            ->assertHasErrors(['legExtras.0.0.name', 'legExtras.0.0.price']);

        $this->assertSame(0, LimoBooking::query()->count());
    }

    public function test_the_count_stays_between_one_and_99(): void
    {
        $component = $this->form()
            ->call('addLegExtra', 0)
            ->call('stepLegExtra', 0, 0, -1);
        $this->assertSame('1', $component->get('legExtras.0.0.qty'));

        $component->set('legExtras.0.0.qty', '99')->call('stepLegExtra', 0, 0, 1);
        $this->assertSame('99', $component->get('legExtras.0.0.qty'));
    }

    public function test_removing_an_extra_takes_it_off_the_price(): void
    {
        $this->formWithExtras()->call('save')->assertHasNoErrors();

        Livewire::test(BookingForm::class, ['id' => LimoBooking::query()->value('id')])
            ->call('removeLegExtra', 0, 1)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(30.0, LimoLeg::query()->sole()->net_amount);
    }

    public function test_removing_a_trip_keeps_the_next_trips_extras_with_it(): void
    {
        $component = $this->form()->call('addLeg')
            ->call('addLegExtra', 1)
            ->set('legExtras.1.0.extra', (string) $this->childSeat()->id)
            ->call('removeLeg', 0);

        $this->assertSame((string) $this->childSeat()->id, $component->get('legExtras.0.0.extra'));
    }

    public function test_the_quick_edit_keeps_the_extras_in_the_price(): void
    {
        $this->formWithExtras()->call('save')->assertHasNoErrors();
        $leg = LimoLeg::query()->sole();

        Livewire::test(Bookings::class)
            ->call('openEdit', $leg->legable_id, $leg->id)
            ->set('editLeg.rate', '40')
            ->call('saveEdit')
            ->assertHasNoErrors();

        $this->assertSame(57.5, LimoLeg::query()->sole()->net_amount);
        $this->assertSame(57.5, (float) LimoBooking::query()->value('fare'));
    }

    public function test_the_invoice_prints_each_extra_as_its_own_line(): void
    {
        $this->formWithExtras()->call('save')->assertHasNoErrors();
        $invoice = LimoBooking::query()->sole()->syncInvoice();

        $data = app(LimoInvoicePdf::class)->viewData($invoice);

        $this->assertSame(20.0, $data['lines'][0]['amount']);
        $this->assertSame('Child seat', $data['lines'][0]['extras'][0]['name']);
        $this->assertSame(10.0, $data['lines'][0]['extras'][0]['amount']);
        $this->assertSame(37.5, $data['subtotal']);

        $pdf = app(LimoInvoicePdf::class)->render($invoice);
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_a_quotation_counts_the_extras_in_its_subtotal(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Gulf Co', 'phone' => '39111111']);
        $quote = LimoQuotation::query()->create(['customer_id' => $customer->id, 'quote_date' => now()->toDateString()]);
        $quote->legs()->create([
            'service_type' => LimoLeg::TYPE_TRANSFER, 'from_location' => 'A', 'to_location' => 'B',
            'rate' => 20, 'line_total' => 20, 'extras_total' => 30, 'net_amount' => 50,
            'extras' => [['extra_id' => null, 'name' => 'Causeway Plus', 'price' => 30, 'qty' => 1]],
        ]);
        $quote->recalcTotal();
        $quote->save();

        $data = app(QuotationPdf::class)->viewData($quote->fresh());

        $this->assertSame(50.0, $data['subtotal']);
        $this->assertSame(50.0, $data['total']);
        $this->assertSame('Causeway Plus', $data['lines'][0]['extras'][0]['name']);
    }

    public function test_the_price_list_page_opens(): void
    {
        // Module routes only register on the boot after an install.
        \Illuminate\Support\Facades\Route::middleware('web')->group(base_path('Modules/Limousine/routes/web.php'));

        $this->get('/app/limousine/extra')->assertOk()->assertSee('Child seat');
        $this->get('/app/limousine/extra/' . $this->childSeat()->id)->assertOk();
    }
}
