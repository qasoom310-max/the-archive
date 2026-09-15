<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\BookingForm;
use Modules\Limousine\Livewire\QuotationForm;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoLocation;
use Tests\TestCase;

/**
 * A customer's trips are usually to the same handful of places, so the
 * From/To autocomplete on a booking/quotation surfaces THAT customer's own
 * past pickup/drop-off locations ahead of the company-wide saved list.
 */
final class LimoCustomerLocationSuggestionsTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private function bookingWithLeg(LimoCustomer $customer, string $from, string $to): LimoBooking
    {
        $booking = LimoBooking::query()->create(['customer_id' => $customer->id]);
        $booking->legs()->create([
            'sequence' => 0, 'service_type' => LimoLeg::TYPE_TRANSFER,
            'from_location' => $from, 'to_location' => $to,
            'start_at' => '2026-07-01 09:00:00', 'days' => 1,
            'rate' => 10, 'rate_basis' => 'trip', 'net_amount' => 10,
            'status' => LimoLeg::STATUS_QUEUE,
        ]);

        return $booking;
    }

    public function test_a_customers_recent_locations_come_back_most_recent_first(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Ahmed']);
        $this->bookingWithLeg($customer, 'Home', 'Office');
        $this->bookingWithLeg($customer, 'Airport', 'The Ritz-Carlton');

        // Most recently created leg's pair comes first.
        $this->assertSame(
            ['Airport', 'The Ritz-Carlton', 'Home', 'Office'],
            $customer->recentLocations(),
        );
    }

    public function test_recent_locations_are_deduplicated(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Ahmed']);
        $this->bookingWithLeg($customer, 'Airport', 'Home');
        $this->bookingWithLeg($customer, 'Home', 'Airport');

        $this->assertSame(['Home', 'Airport'], $customer->recentLocations());
    }

    public function test_a_customer_with_no_bookings_has_no_recent_locations(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'New Customer']);

        $this->assertSame([], $customer->recentLocations());
    }

    /** Only THIS customer's own trips count — not the whole company's. */
    public function test_another_customers_locations_are_not_mixed_in(): void
    {
        $ahmed = LimoCustomer::query()->create(['name' => 'Ahmed']);
        $sara = LimoCustomer::query()->create(['name' => 'Sara']);
        $this->bookingWithLeg($sara, 'Sara Home', 'Sara Office');
        $this->bookingWithLeg($ahmed, 'Ahmed Home', 'Ahmed Office');

        $this->assertSame(['Ahmed Home', 'Ahmed Office'], $ahmed->recentLocations());
    }

    public function test_the_booking_form_suggests_the_selected_customers_locations_first(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Ahmed']);
        $this->bookingWithLeg($customer, 'Ahmed Home', 'Ahmed Office');
        LimoLocation::query()->create(['name' => 'Bahrain Airport', 'active' => true]);

        Livewire::test(BookingForm::class)
            ->set('customer_id', $customer->id)
            ->assertViewHas('locationNames', function (array $names) {
                return $names === ['Ahmed Home', 'Ahmed Office', 'Bahrain Airport'];
            });
    }

    /** No customer picked yet — just the company-wide saved list. */
    public function test_with_no_customer_selected_only_the_saved_list_shows(): void
    {
        LimoLocation::query()->create(['name' => 'Bahrain Airport', 'active' => true]);

        Livewire::test(BookingForm::class)
            ->assertViewHas('locationNames', fn (array $names) => $names === ['Bahrain Airport']);
    }

    /** Shared trait, so the quotation form offers the same suggestions. */
    public function test_the_quotation_form_shares_the_same_suggestions(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'LineCo']);
        $this->bookingWithLeg($customer, 'LineCo Depot', 'LineCo Yard');

        Livewire::test(QuotationForm::class)
            ->set('customer_id', $customer->id)
            ->assertViewHas('locationNames', fn (array $names) => $names === ['LineCo Depot', 'LineCo Yard']);
    }
}
