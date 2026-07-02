<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Modules\Limousine\Livewire\BookingForm;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Rental\Livewire\OrderForm;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Tests\TestCase;

/**
 * Rental + Limousine share ONE customer list (the `rental_customers` table):
 * a customer is entered once and seen in both apps, the old `limo_customers`
 * table is merged away, and each customer carries a service tag (Rental /
 * Limousine / Both) derived from their actual bookings.
 */
final class SharedTransportCustomerTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        // Installing limousine pulls in rental (its new dependency), so the
        // shared table exists and the merge migration has run.
        app(ModuleManager::class)->install('limousine');
    }

    public function test_the_separate_limo_customers_table_is_merged_away(): void
    {
        $this->assertTrue(Schema::hasTable('rental_customers'));
        $this->assertFalse(Schema::hasTable('limo_customers'));
    }

    public function test_a_customer_is_one_shared_record_across_both_apps(): void
    {
        $created = RentalCustomer::query()->create(['name' => 'Sara', 'phone' => '39000001']);

        // The limousine app reads the very same row from the shared table.
        $this->assertSame(1, LimoCustomer::query()->count());
        $this->assertSame($created->id, LimoCustomer::query()->first()?->id);
        $this->assertSame('Sara', LimoCustomer::query()->find($created->id)?->name);
    }

    public function test_service_tag_is_derived_from_actual_bookings(): void
    {
        $rentalOnly = RentalCustomer::query()->create(['name' => 'R only']);
        $limoOnly = RentalCustomer::query()->create(['name' => 'L only']);
        $both = RentalCustomer::query()->create(['name' => 'Both']);
        $none = RentalCustomer::query()->create(['name' => 'None']);

        $this->makeRentalOrder($rentalOnly->id);
        $this->makeRentalOrder($both->id);
        $this->makeLimoBooking($limoOnly->id);
        $this->makeLimoBooking($both->id);

        $this->assertSame('rental', $rentalOnly->fresh()?->service_tag);
        $this->assertSame('limousine', $limoOnly->fresh()?->service_tag);
        $this->assertSame('both', $both->fresh()?->service_tag);
        $this->assertSame('none', $none->fresh()?->service_tag);
        $this->assertSame('—', $none->fresh()?->service_tag_label);
        $this->assertSame(__('Both'), $both->fresh()?->service_tag_label);
    }

    public function test_inline_new_customer_on_the_rental_order_form(): void
    {
        Livewire::test(OrderForm::class)
            ->call('openCustomerModal')
            ->set('newCustomer.name', '  Khalid ')
            ->set('newCustomer.phone', '39000002')
            ->call('saveCustomer')
            ->assertHasNoErrors()
            ->assertSet('addingCustomer', false)
            ->assertSet('customer_id', fn ($id): bool => $id !== null);

        $customer = RentalCustomer::query()->firstWhere('name', 'Khalid');
        $this->assertNotNull($customer);
        // Immediately visible to the limousine app too (one shared list).
        $this->assertSame(1, LimoCustomer::query()->count());
    }

    public function test_inline_new_customer_on_the_limousine_booking_form(): void
    {
        Livewire::test(BookingForm::class)
            ->call('openCustomerModal')
            ->set('newCustomer.name', 'Mona')
            ->set('newCustomer.phone', '39000003')
            ->set('newCustomer.email', 'mona@example.com')
            ->set('newCustomer.type', 'company')
            ->call('saveCustomer')
            ->assertHasNoErrors()
            ->assertSet('customer_id', fn ($id): bool => $id !== null);

        // The customer the limo desk just added shows in the rental list, with
        // the individual/company type it was given.
        $mona = RentalCustomer::query()->where('name', 'Mona')->sole();
        $this->assertSame('company', $mona->type);
        $this->assertSame('39000003', $mona->phone);
    }

    public function test_the_limousine_new_customer_requires_phone_email_and_type(): void
    {
        Livewire::test(BookingForm::class)
            ->call('openCustomerModal')
            ->set('newCustomer.name', 'NoContact')
            ->set('newCustomer.phone', '')
            ->set('newCustomer.email', '')
            ->call('saveCustomer')
            ->assertHasErrors(['newCustomer.phone', 'newCustomer.email']);

        $this->assertSame(0, RentalCustomer::query()->where('name', 'NoContact')->count());
    }

    public function test_inline_new_customer_requires_a_name(): void
    {
        Livewire::test(OrderForm::class)
            ->call('openCustomerModal')
            ->set('newCustomer.name', '')
            ->call('saveCustomer')
            ->assertHasErrors(['newCustomer.name']);

        $this->assertSame(0, RentalCustomer::query()->count());
    }

    private function makeRentalOrder(int $customerId): void
    {
        RentalOrder::query()->create([
            'customer_id' => $customerId,
            'start_date' => Carbon::parse('2026-06-25'),
            'end_date' => Carbon::parse('2026-06-26'),
            'rate_type' => 'daily',
            'rate' => 10,
        ]);
    }

    private function makeLimoBooking(int $customerId): void
    {
        LimoBooking::query()->create([
            'customer_id' => $customerId,
            'pickup_at' => Carbon::parse('2026-06-25 10:00'),
            'fare' => 5,
        ]);
    }
}
