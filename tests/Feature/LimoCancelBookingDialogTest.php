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
use Modules\Limousine\Models\LimoLeg;
use Tests\TestCase;

/**
 * Cancelling a booking asks in the page, with Yes and No.
 *
 * It used to go through `wire:confirm`, which hands the browser its own
 * dialog — and on an action already called "Cancel", the OS buttons read
 * "Cancel" and "OK", one of which looks like it means "don't cancel".
 */
final class LimoCancelBookingDialogTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private function booking(string $status = LimoBooking::STATUS_QUEUE): LimoBooking
    {
        $customer = LimoCustomer::query()->create(['name' => 'Braxtone Plus W.L.L', 'phone' => '33112233']);

        $booking = LimoBooking::query()->create([
            'reference' => 'BK/15478',
            'customer_id' => $customer->id,
            'pickup_at' => '2026-09-20 17:30:00',
            'status' => $status,
        ]);

        LimoLeg::query()->create([
            'legable_type' => LimoBooking::class,
            'legable_id' => $booking->id,
            'sequence' => 1,
            'from_location' => 'Arad',
            'to_location' => 'Bahrain airport',
            'start_at' => '2026-09-20 17:30:00',
            'status' => $status,
            'rate' => 14, 'net_amount' => 14,
        ]);

        return $booking;
    }

    public function test_the_booking_page_asks_with_yes_and_no_not_the_browsers_dialog(): void
    {
        $html = Livewire::test(BookingForm::class, ['id' => $this->booking()->id])->html();

        $this->assertStringContainsString('Cancel this booking?', $html);
        $this->assertStringContainsString('>Yes<', $html);
        $this->assertStringContainsString('>No<', $html);
        $this->assertStringNotContainsString('wire:confirm', $html);
        // No is the way out, so it comes first.
        $this->assertLessThan(mb_strpos($html, '>Yes<'), mb_strpos($html, '>No<'));
    }

    public function test_saying_yes_cancels_the_booking_and_its_trips(): void
    {
        $booking = $this->booking();

        Livewire::test(BookingForm::class, ['id' => $booking->id])->call('cancelBooking');

        $this->assertSame(LimoBooking::STATUS_CANCELLED, $booking->refresh()->status);
        $this->assertSame(LimoLeg::STATUS_CANCELLED, $booking->legs()->sole()->status);
    }

    /** A booking already cancelled has nothing to ask about. */
    public function test_a_cancelled_booking_offers_no_cancel_dialog(): void
    {
        $html = Livewire::test(BookingForm::class, ['id' => $this->booking(LimoBooking::STATUS_CANCELLED)->id])->html();

        $this->assertStringNotContainsString('Cancel this booking?', $html);
    }
}
