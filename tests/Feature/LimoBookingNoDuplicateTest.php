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
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoLeg;
use Tests\TestCase;

/**
 * Making a booking and then changing it must change THAT booking, never add a
 * second one beside it.
 */
final class LimoBookingNoDuplicateTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('limousine');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function newForm(): \Livewire\Features\SupportTesting\Testable
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

    private function assertOneOfEach(): void
    {
        $this->assertSame(1, LimoBooking::query()->count(), 'bookings');
        $this->assertSame(1, LimoLeg::query()->where('legable_type', (new LimoBooking())->getMorphClass())->count(), 'trips');
        $this->assertSame(1, LimoInvoice::query()->count(), 'invoices');
    }

    public function test_saving_the_new_booking_form_twice_does_not_make_two(): void
    {
        $form = $this->newForm();
        $form->call('save')->assertHasNoErrors();
        $form->set('legs.0.rate', '25')->call('save')->assertHasNoErrors();

        $this->assertOneOfEach();
        $this->assertSame(25.0, (float) LimoBooking::query()->value('fare'));
    }

    public function test_editing_a_saved_booking_changes_it_in_place(): void
    {
        $this->newForm()->call('save')->assertHasNoErrors();
        $booking = LimoBooking::query()->sole();

        Livewire::test(BookingForm::class, ['id' => $booking->id])
            ->set('legs.0.rate', '30')
            ->set('legs.0.to_location', 'Juffair')
            ->call('save')->assertHasNoErrors();

        $this->assertOneOfEach();
        $this->assertSame(30.0, (float) LimoBooking::query()->value('fare'));
        $this->assertSame('Juffair', LimoLeg::query()->value('to_location'));
    }

    public function test_saving_a_new_quotation_twice_does_not_make_two(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Helen Friberg', 'phone' => '39000000']);

        $form = Livewire::test(\Modules\Limousine\Livewire\QuotationForm::class)
            ->set('customer_id', $customer->id)
            ->set('requested_by', 'Office')
            ->set('legs.0.from_location', 'Hotel')
            ->set('legs.0.to_location', 'Airport')
            ->set('legs.0.estimated_hours', '2')
            ->set('legs.0.start_at', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('legs.0.rate', '20');

        $form->call('save')->assertHasNoErrors();
        $form->set('legs.0.rate', '22')->call('save')->assertHasNoErrors();

        $this->assertSame(1, \Modules\Limousine\Models\LimoQuotation::query()->count());
        $this->assertSame(1, LimoLeg::query()->where('legable_type', (new \Modules\Limousine\Models\LimoQuotation())->getMorphClass())->count());
    }

    public function test_the_save_button_locks_while_saving(): void
    {
        $this->assertStringContainsString(
            'wire:click="save" wire:loading.attr="disabled" wire:target="save"',
            Livewire::test(BookingForm::class)->html(),
        );
    }

    public function test_editing_a_trip_from_the_list_changes_it_in_place(): void
    {
        $this->newForm()->call('save')->assertHasNoErrors();
        $leg = LimoLeg::query()->sole();

        Livewire::test(Bookings::class)
            ->call('openEdit', $leg->legable_id, $leg->id)
            ->set('editLeg.rate', '40')
            ->call('saveEdit')
            ->assertHasNoErrors();

        $this->assertOneOfEach();
        $this->assertSame(40.0, (float) LimoBooking::query()->value('fare'));
    }
}
