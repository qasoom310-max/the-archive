<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Limousine\Livewire\BookingForm;
use Modules\Limousine\Livewire\QuotationForm;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoQuotation;
use Tests\TestCase;

/**
 * A leg's rate is quoted in BHD by default; the office may instead pick a
 * currency an outside partner quoted in and type the rate in THAT currency —
 * the exchange rate to BHD is looked up live (never typed), and the leg still
 * saves a plain BHD `rate` — every downstream calculation (line_total,
 * net_amount, the booking fare, the invoice) reads it exactly as before,
 * untouched by this feature.
 */
final class LimoLegCurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private function fakeSarRate(float $rate = 0.376182): void
    {
        Http::fake([
            'open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['BHD' => $rate]]),
        ]);
    }

    public function test_a_new_leg_defaults_to_bhd_with_no_conversion_fields(): void
    {
        Http::fake(); // no lookup should ever fire for a BHD-default leg

        Livewire::test(BookingForm::class)
            ->assertSet('legs.0.currency', 'BHD')
            ->assertSet('legs.0.quote_rate', '')
            ->assertSet('legs.0.exchange_rate', '');

        Http::assertNothingSent();
    }

    public function test_picking_a_foreign_currency_looks_up_the_rate_and_derives_the_bhd_rate_live(): void
    {
        $this->fakeSarRate();

        Livewire::test(BookingForm::class)
            ->set('legs.0.currency', 'SAR')
            ->assertSet('legs.0.exchange_rate', '0.376182')
            ->set('legs.0.quote_rate', '100')
            ->set('legs.0.car_details', 'Sedan')
            ->assertSet('legs.0.rate', (string) round(100 * 0.376182, 3));

        Http::assertSentCount(1); // the second field change reuses the already-fetched rate
    }

    public function test_switching_back_to_bhd_clears_the_conversion_fields_and_keeps_the_last_rate(): void
    {
        $this->fakeSarRate();

        Livewire::test(BookingForm::class)
            ->set('legs.0.currency', 'SAR')
            ->set('legs.0.quote_rate', '100')
            ->set('legs.0.car_details', 'Sedan')
            ->set('legs.0.currency', 'BHD')
            ->assertSet('legs.0.quote_rate', '')
            ->assertSet('legs.0.exchange_rate', '')
            // The BHD figure just derived stays put, directly editable.
            ->assertSet('legs.0.rate', (string) round(100 * 0.376182, 3));
    }

    public function test_a_foreign_currency_leg_saves_the_converted_bhd_rate_and_remembers_the_quote(): void
    {
        $this->fakeSarRate();
        $customer = LimoCustomer::query()->create(['name' => 'Hamad']);

        Livewire::test(BookingForm::class)
            ->set('customer_id', $customer->id)
            ->set('requested_by', 'Sara')
            ->set('payment_method', 'cash')
            ->set('legs.0.service_type', 'transfer')
            ->set('legs.0.from_location', 'Airport')
            ->set('legs.0.to_location', 'City Centre')
            ->set('legs.0.start_at', '2026-07-01T14:30')
            ->set('legs.0.currency', 'SAR')
            ->set('legs.0.quote_rate', '100')
            ->set('legs.0.car_details', 'Sedan')
            ->set('legs.0.rate_basis', 'trip')
            ->call('save')
            ->assertHasNoErrors();

        $booking = LimoBooking::query()->with('legs')->sole();
        $leg = $booking->legs->sole();

        $bhdRate = round(100 * 0.376182, 3);
        $this->assertSame('SAR', $leg->currency);
        $this->assertEqualsWithDelta(100.0, $leg->quote_rate, 0.0001);
        $this->assertEqualsWithDelta(0.376182, $leg->exchange_rate, 0.000001);
        $this->assertEqualsWithDelta($bhdRate, $leg->rate, 0.001);
        // The booking's fare is built from the BHD rate, same as any leg.
        $this->assertEqualsWithDelta($bhdRate, $booking->fare, 0.001);
    }

    public function test_a_bhd_leg_saves_with_no_quote_currency_metadata(): void
    {
        Http::fake(); // no lookup should fire for a leg that never leaves BHD
        $customer = LimoCustomer::query()->create(['name' => 'Hamad']);

        Livewire::test(BookingForm::class)
            ->set('customer_id', $customer->id)
            ->set('requested_by', 'Sara')
            ->set('payment_method', 'cash')
            ->set('legs.0.service_type', 'transfer')
            ->set('legs.0.from_location', 'Airport')
            ->set('legs.0.to_location', 'City Centre')
            ->set('legs.0.start_at', '2026-07-01T14:30')
            ->set('legs.0.rate', '18.5')
            ->set('legs.0.car_details', 'Sedan')
            ->set('legs.0.rate_basis', 'trip')
            ->call('save')
            ->assertHasNoErrors();

        $leg = LimoBooking::query()->sole()->legs->sole();
        $this->assertSame('BHD', $leg->currency);
        $this->assertNull($leg->quote_rate);
        $this->assertNull($leg->exchange_rate);
        $this->assertEqualsWithDelta(18.5, $leg->rate, 0.001);
        Http::assertNothingSent();
    }

    /** A network hiccup during the live lookup blocks the save with a friendly, retryable error. */
    public function test_a_failed_lookup_blocks_the_save_and_can_be_retried(): void
    {
        // A single fake registration serving two responses in turn — a second
        // Http::fake() call for the same URL would NOT override the first
        // (Laravel's stub callbacks are matched first-registered-wins), so
        // the retry has to be modelled as a sequence, not a re-fake.
        Http::fakeSequence('open.er-api.com/*')
            ->pushStatus(503)
            ->pushStatus(503)
            ->push(['result' => 'success', 'rates' => ['BHD' => 0.376182]]);
        $customer = LimoCustomer::query()->create(['name' => 'Hamad']);

        $component = Livewire::test(BookingForm::class)
            ->set('customer_id', $customer->id)
            ->set('requested_by', 'Sara')
            ->set('payment_method', 'cash')
            ->set('legs.0.service_type', 'transfer')
            ->set('legs.0.from_location', 'Airport')
            ->set('legs.0.to_location', 'City Centre')
            ->set('legs.0.start_at', '2026-07-01T14:30')
            ->set('legs.0.currency', 'SAR')
            ->set('legs.0.quote_rate', '100')
            ->set('legs.0.car_details', 'Sedan')
            ->assertSet('legs.0.exchange_rate', '')
            ->assertSet('legs.0.rate', '')
            ->set('legs.0.rate_basis', 'trip')
            ->call('save')
            ->assertHasErrors(['legs.0.exchange_rate']);

        $this->assertSame(0, LimoBooking::query()->count());

        // Retrying once the connection is back (the sequence's 3rd response) unblocks the save.
        $component->call('retryLegExchangeRate', 0)
            ->assertSet('legs.0.exchange_rate', '0.376182')
            ->assertSet('legs.0.rate', (string) round(100 * 0.376182, 3))
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, LimoBooking::query()->count());
    }

    /** Re-opening a saved foreign-currency leg shows the original figures back — no fresh lookup. */
    public function test_editing_a_saved_booking_reloads_the_quote_currency_and_rate_without_a_fresh_lookup(): void
    {
        Http::fake();
        $customer = LimoCustomer::query()->create(['name' => 'Hamad']);
        $booking = LimoBooking::query()->create(['customer_id' => $customer->id]);
        $booking->legs()->create([
            'sequence' => 0, 'service_type' => LimoLeg::TYPE_TRANSFER,
            'from_location' => 'Airport', 'to_location' => 'Riyadh',
            'start_at' => '2026-07-01 09:00:00', 'days' => 1,
            'rate' => 37.618, 'currency' => 'SAR', 'quote_rate' => 100, 'exchange_rate' => 0.37618,
            'rate_basis' => 'trip', 'net_amount' => 37.618, 'line_total' => 37.618,
            'status' => LimoLeg::STATUS_QUEUE,
        ]);

        Livewire::test(BookingForm::class, ['id' => $booking->id])
            ->assertSet('legs.0.currency', 'SAR')
            ->assertSet('legs.0.quote_rate', '100')
            ->assertSet('legs.0.exchange_rate', '0.37618')
            ->assertSet('legs.0.rate', '37.618');

        Http::assertNothingSent();
    }

    /** Shared trait, so the quotation form gets the same live conversion for free. */
    public function test_the_quotation_form_shares_the_same_currency_conversion(): void
    {
        $this->fakeSarRate();
        $customer = LimoCustomer::query()->create(['name' => 'LineCo']);

        Livewire::test(QuotationForm::class)
            ->set('customer_id', $customer->id)
            ->set('requested_by', 'Sara')
            ->set('legs.0.service_type', 'transfer')
            ->set('legs.0.from_location', 'Bahrain Airport')
            ->set('legs.0.to_location', 'Riyadh')
            ->set('legs.0.start_at', '2026-07-05T09:00')
            ->set('legs.0.currency', 'SAR')
            ->set('legs.0.quote_rate', '100')
            ->set('legs.0.car_details', 'Sedan')
            ->set('legs.0.rate_basis', 'trip')
            ->call('save')
            ->assertHasNoErrors();

        $leg = LimoQuotation::query()->sole()->legs->sole();
        $this->assertSame('SAR', $leg->currency);
        $this->assertEqualsWithDelta(round(100 * 0.376182, 3), $leg->rate, 0.001);
    }
}
