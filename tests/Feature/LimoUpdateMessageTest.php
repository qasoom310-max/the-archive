<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\Bookings;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Tests\TestCase;

/**
 * What the office is told after editing a trip from the queue.
 *
 * It is not an internal notice: this is the line forwarded to the customer, so
 * it has to name the REFERENCE they quote — and be worded the same whether the
 * change was made on the booking form or in the queue's quick-edit dialog.
 */
final class LimoUpdateMessageTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    /**
     * @param  list<string>  $legRefs
     * @return array{0: LimoBooking, 1: list<LimoLeg>}
     */
    private function booking(array $legRefs): array
    {
        $customer = LimoCustomer::query()->create(['name' => 'Qassim Makhlooq']);

        $booking = LimoBooking::query()->create([
            'reference' => 'BK/00005',
            'customer_id' => $customer->id,
            'pickup_at' => now()->addDay(),
            'status' => LimoBooking::STATUS_QUEUE,
            'prepared_by' => 'Qassim',
        ]);

        $legs = [];
        foreach ($legRefs as $i => $ref) {
            $legs[] = LimoLeg::query()->create([
                'legable_type' => LimoBooking::class,
                'legable_id' => $booking->id,
                'sequence' => $i,
                'reference' => $ref,
                'status' => LimoLeg::STATUS_QUEUE,
                'start_at' => now()->addDay(),
                'from_location' => 'Home',
                'to_location' => 'Dammam Airport',
                'rate' => 45, 'net_amount' => 45,
            ]);
        }

        $booking->recalcTotal();
        $booking->save();

        return [$booking, $legs];
    }

    /** The reported gap: "Booking updated." with no reference to forward. */
    public function test_the_update_message_names_the_edited_trip(): void
    {
        [$booking, $legs] = $this->booking(['10007']);

        // Asserted on the RENDER, not the session: the flash is consumed by the
        // re-render that follows the save, and what matters is that the office
        // can see it anyway.
        Livewire::test(Bookings::class)
            ->call('openEdit', $booking->id, $legs[0]->id)
            ->set('edit.pax_name', 'Qassim')
            ->call('saveEdit')
            ->assertSee('Booking has been updated successfully. Ref. # 10007');
    }

    /** Worded exactly as the booking form words it, so the customer sees one voice. */
    public function test_it_matches_the_booking_form_wording(): void
    {
        [$booking, $legs] = $this->booking(['10007']);

        Livewire::test(Bookings::class)
            ->call('openEdit', $booking->id, $legs[0]->id)
            ->call('saveEdit')
            // The same sentence the booking form flashes, not a second wording.
            ->assertSee('Booking has been updated successfully. Ref. # 10007');
    }

    /** Editing the trip that was clicked names THAT one, not the first. */
    public function test_it_names_the_clicked_trip_of_several(): void
    {
        [$booking, $legs] = $this->booking(['10007', '10008', '10009']);

        Livewire::test(Bookings::class)
            ->call('openEdit', $booking->id, $legs[2]->id)
            ->call('saveEdit')
            ->assertSee('Booking has been updated successfully. Ref. # 10009')
            ->assertDontSee('Booking has been updated successfully. Ref. # 10007');
    }

    /** With no single trip behind the edit, every reference is named. */
    public function test_a_booking_wide_edit_names_them_all(): void
    {
        [$booking] = $this->booking(['10007', '10008']);

        Livewire::test(Bookings::class)
            ->call('openEdit', $booking->id)
            ->call('saveEdit')
            ->assertSee('Booking has been updated successfully. Ref. # 10007')
            ->assertSee('Booking has been updated successfully. Ref. # 10008');
    }

    /**
     * A NEW booking confirms in the same place, in the same words.
     *
     * It used to go to the corner toast while an edit went to the list's banner,
     * so the same fact arrived two different ways depending on which screen it
     * came from — and the reference faded out of the corner before it could be
     * copied.
     */
    public function test_a_new_booking_confirms_in_the_same_banner(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Qassim Makhlooq']);

        Livewire::test(\Modules\Limousine\Livewire\BookingForm::class)
            ->set('customer_id', $customer->id)
            ->set('requested_by', 'Office')
            ->set('legs.0.from_location', 'Home')
            ->set('legs.0.to_location', 'Dammam Airport')
            ->set('legs.0.start_at', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('legs.0.rate', '45')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect('/app/limousine/booking');

        $leg = LimoLeg::query()->firstOrFail();

        // The banner on the list it lands on, with the reference and a way to
        // copy it — the same treatment an edit gets.
        Livewire::test(Bookings::class)
            ->assertSee('Booking done successfully. Ref. # ' . $leg->reference)
            ->assertSee('Copy this message');
    }

    /** The message is on screen with something to copy it with. */
    public function test_the_message_is_shown_with_a_copy_button(): void
    {
        [$booking, $legs] = $this->booking(['10007']);

        Livewire::test(Bookings::class)
            ->call('openEdit', $booking->id, $legs[0]->id)
            ->call('saveEdit')
            ->assertSee('Booking has been updated successfully. Ref. # 10007')
            ->assertSee('Copy this message');
    }
}
