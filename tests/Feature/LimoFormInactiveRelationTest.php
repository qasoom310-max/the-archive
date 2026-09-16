<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\BookingForm;
use Modules\Limousine\Livewire\InvoiceForm;
use Modules\Limousine\Livewire\QuotationForm;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoQuotation;
use Tests\TestCase;

/**
 * Opening an existing booking/quotation/invoice whose customer was later
 * deactivated must still show that customer in the picker — not a blank
 * "— Select —", which reads as if the record has no customer at all.
 */
final class LimoFormInactiveRelationTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('limousine');
    }

    public function test_booking_form_keeps_a_deactivated_customer_visible(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $customer = LimoCustomer::query()->create(['name' => 'Eslam Zein', 'active' => false]);
        $booking = LimoBooking::query()->create(['customer_id' => $customer->id]);

        Livewire::test(BookingForm::class, ['id' => $booking->id])
            ->assertSet('customer_id', $customer->id)
            ->assertViewHas('customers', fn ($customers) => $customers->contains('id', $customer->id));
    }

    public function test_quotation_form_keeps_a_deactivated_customer_visible(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $customer = LimoCustomer::query()->create(['name' => 'Eslam Zein', 'active' => false]);
        $quote = LimoQuotation::query()->create(['customer_id' => $customer->id]);

        Livewire::test(QuotationForm::class, ['id' => $quote->id])
            ->assertSet('customer_id', $customer->id)
            ->assertViewHas('customers', fn ($customers) => $customers->contains('id', $customer->id));
    }

    public function test_invoice_form_keeps_a_deactivated_customer_visible(): void
    {
        // InvoiceForm::mount() gates opening an EXISTING invoice on super-admin.
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => true]));
        $customer = LimoCustomer::query()->create(['name' => 'Eslam Zein', 'active' => false]);
        $invoice = LimoInvoice::query()->create(['customer_id' => $customer->id]);

        Livewire::test(InvoiceForm::class, ['id' => $invoice->id])
            ->assertSet('customer_id', $customer->id)
            ->assertViewHas('customers', fn ($customers) => $customers->contains('id', $customer->id));
    }
}
