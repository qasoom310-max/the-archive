<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\Auth\ModelAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\BookingForm;
use Modules\Limousine\Livewire\Bookings;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Tests\TestCase;

/**
 * Quick-preview dialog on the bookings list: a glance at the whole job —
 * customer, every trip, money — from the reference cell, without leaving the
 * list or committing to the full booking page.
 */
final class LimoBookingPreviewTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private function booking(): LimoBooking
    {
        $customer = LimoCustomer::query()->create(['name' => 'Helen Friberg', 'phone' => '33112233']);

        $booking = LimoBooking::query()->create([
            'reference' => 'LIMO/14602',
            'customer_id' => $customer->id,
            'status' => LimoBooking::STATUS_QUEUE,
            'fare' => 45.0,
            'amount' => 45.0,
            'advance' => 20.0,
            'notes' => 'Meet at arrivals hall',
        ]);
        $booking->legs()->create([
            'sequence' => 0, 'service_type' => LimoLeg::TYPE_TRANSFER,
            'from_location' => 'Airport', 'to_location' => 'Hotel',
            'start_at' => '2026-09-05 09:00:00', 'days' => 1,
            'rate' => 45.0, 'rate_basis' => 'trip', 'net_amount' => 45.0,
            'status' => LimoLeg::STATUS_QUEUE,
        ]);

        return $booking;
    }

    public function test_opening_the_preview_loads_the_booking_with_its_legs(): void
    {
        $booking = $this->booking();

        Livewire::test(Bookings::class)
            ->call('openPreview', $booking->id)
            ->assertSet('previewingId', $booking->id)
            ->assertSee('LIMO/14602')
            ->assertSee('Helen Friberg')
            ->assertSee('Airport')
            ->assertSee('Hotel')
            ->assertSee('Meet at arrivals hall');
    }

    public function test_closing_the_preview_clears_it(): void
    {
        $booking = $this->booking();

        Livewire::test(Bookings::class)
            ->call('openPreview', $booking->id)
            ->call('closePreview')
            ->assertSet('previewingId', null);
    }

    /**
     * Read-only: anyone who may see the queue may open the preview, unlike
     * Edit/Assign/Collect which all require Write.
     */
    public function test_read_access_alone_can_open_the_preview(): void
    {
        $booking = $this->booking();

        $this->grantEveryone('limousine.booking');
        $this->readOnly('limousine.booking');
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        Livewire::test(Bookings::class)
            ->call('openPreview', $booking->id)
            ->assertSet('previewingId', $booking->id);
    }

    /**
     * The reference in the queue copies the trip to the clipboard on click —
     * reverted 2026-09-16 after the office asked for the old behaviour back.
     * It briefly opened the preview instead; the preview itself is still
     * reachable from the just-saved banner's "View" link.
     */
    public function test_the_reference_in_the_queue_copies_the_trip_instead_of_opening_the_preview(): void
    {
        $booking = $this->booking();
        $leg = $booking->legs()->firstOrFail();

        $html = Livewire::test(Bookings::class)->html();

        $this->assertStringNotContainsString("wire:click=\"openPreview({$booking->id})\"", $html);
        $this->assertStringContainsString('store.limoTrip.copy', $html);
        $this->assertStringContainsString('Copy trip details for WhatsApp', $html);
        $this->assertStringContainsString($leg->reference, $html);
    }

    /**
     * Six columns do not fit a phone. The wrapper used to be `overflow-hidden`
     * (for the rounded corners), which CUT the vehicle, the status and every
     * amount off with no way to reach them — the preview was unusable on the
     * phone the drivers' desk actually runs it on.
     */
    public function test_the_preview_table_scrolls_sideways_on_a_phone(): void
    {
        $booking = $this->booking();

        $html = Livewire::test(Bookings::class)->call('openPreview', $booking->id)->html();

        $table = strstr($html, '<th class="whitespace-nowrap px-3 py-2 text-start">', true);
        $this->assertIsString($table, 'The preview trip table has been rewritten — re-point this guard.');

        // The wrapper immediately around it lets the overflow be reached.
        $at = strrpos($table, '<div ');
        $this->assertNotFalse($at);
        $wrapper = substr($table, $at);
        $this->assertStringContainsString('overflow-x-auto', $wrapper);
        $this->assertStringNotContainsString('overflow-hidden', $wrapper);

        // …and the columns keep a readable width instead of each one wrapping
        // into a stack of single words to fit the screen.
        $this->assertStringContainsString('min-w-[38rem]', $html);
    }

    /** Closed, the copy action is nowhere on the page; open, it is — per leg. */
    public function test_the_preview_offers_a_copy_button_per_leg(): void
    {
        $booking = $this->booking();

        Livewire::test(Bookings::class)
            ->call('openPreview', $booking->id)
            ->assertSee('Copy trip details for WhatsApp');
    }

    /**
     * The yellow banner shown right after booking/editing carries the id of
     * the booking that was just saved, and its "View" button opens that
     * booking's preview directly.
     */
    public function test_the_success_banner_offers_a_view_button_that_opens_that_booking(): void
    {
        $booking = $this->booking();
        session()->flash('booking_status', 'Booking done successfully. Ref. # 10021');
        session()->flash('booking_status_id', $booking->id);

        $component = Livewire::test(Bookings::class);
        $this->assertStringContainsString("wire:click=\"openPreview({$booking->id})\"", $component->html());

        $component->call('openPreview', $booking->id)->assertSet('previewingId', $booking->id);
    }

    /**
     * The banner's 3-dot menu holds just View and the payment link — one link
     * per trip on a round trip, named by its number. No View button outside it.
     */
    public function test_the_success_banner_menu_offers_view_and_a_payment_link_per_trip(): void
    {
        $config = \Modules\Limousine\Models\LimoPortalConfiguration::current();
        $config->portal_url = 'https://wanaan-bh.com';
        $config->shared_secret = 'secret';
        $config->enabled = true;
        $config->save();

        $booking = $this->booking();
        $return = $booking->legs()->create([
            'sequence' => 1, 'service_type' => LimoLeg::TYPE_TRANSFER,
            'from_location' => 'Hotel', 'to_location' => 'Airport',
            'start_at' => '2026-09-08 18:00:00', 'days' => 1,
            'rate' => 45.0, 'rate_basis' => 'trip', 'net_amount' => 45.0,
            'status' => LimoLeg::STATUS_QUEUE,
        ]);
        $first = $booking->legs()->orderBy('sequence')->firstOrFail();
        session()->flash('booking_status', 'Booking done successfully.');
        session()->flash('booking_status_id', $booking->id);

        $html = Livewire::test(Bookings::class)->html();

        $this->assertStringContainsString('data-saved-booking-actions', $html);
        $this->assertSame(1, substr_count($html, "wire:click=\"openPreview({$booking->id})\""), 'View lives only in the menu.');
        foreach ([$first, $return] as $trip) {
            $this->assertStringContainsString("wire:click=\"openPaymentLink({$trip->id})\"", $html);
            $this->assertStringContainsString(e(__('Create payment link — trip :ref', ['ref' => $trip->reference])), $html);
        }
    }

    /** No flashed id (a plain status message, or none at all) — no View button. */
    public function test_no_view_button_without_a_flashed_booking_id(): void
    {
        session()->flash('booking_status', 'Trip cancelled.');

        $html = Livewire::test(Bookings::class)->html();

        $this->assertStringNotContainsString('wire:click="openPreview(', $html);
    }

    /** Creating a booking flashes its id, so the very next page can offer View. */
    public function test_saving_a_new_booking_flashes_its_id_for_the_banners_view_button(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Hamad']);

        Livewire::test(BookingForm::class)
            ->set('customer_id', $customer->id)
            ->set('requested_by', 'Sara')
            ->set('payment_method', 'cash')
            ->set('legs.0.service_type', 'transfer')
            ->set('legs.0.from_location', 'Airport')
            ->set('legs.0.to_location', 'City Centre')
            ->set('legs.0.start_at', '2026-07-01T14:30')
            ->set('legs.0.rate', 18.5)
            ->set('legs.0.car_details', 'Sedan')
            ->set('legs.0.rate_basis', 'trip')
            ->call('save')
            ->assertHasNoErrors();

        $booking = LimoBooking::query()->sole();
        $this->assertSame($booking->id, session('booking_status_id'));
    }

    /** Downgrade a global grant to read-only. */
    private function readOnly(string $model): void
    {
        ModelAccess::query()
            ->where('model', $model)
            ->whereNull('group_id')
            ->update(['perm_write' => false, 'perm_create' => false, 'perm_unlink' => false]);
    }
}
