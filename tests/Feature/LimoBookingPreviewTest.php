<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\Auth\ModelAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
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

    /** Downgrade a global grant to read-only. */
    private function readOnly(string $model): void
    {
        ModelAccess::query()
            ->where('model', $model)
            ->whereNull('group_id')
            ->update(['perm_write' => false, 'perm_create' => false, 'perm_unlink' => false]);
    }
}
