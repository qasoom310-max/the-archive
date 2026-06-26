<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Rental\Livewire\CustomerForm;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * Customer 360: opening a customer shows their whole rental history (and spend)
 * on the customer page, so you don't have to dig through the orders list.
 */
final class RentalCustomerProfileTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');
    }

    public function test_the_customer_page_lists_their_rental_orders_and_spend(): void
    {
        $customer = RentalCustomer::query()->create(['name' => 'Qassim Makhlooq', 'phone' => '38467744']);
        $car = Vehicle::query()->create(['name' => 'Eco Sport', 'plate_no' => '203011', 'daily_rate' => 10]);
        $order = RentalOrder::query()->create([
            'customer_id' => $customer->id, 'vehicle_id' => $car->id,
            'start_date' => Carbon::now(), 'end_date' => Carbon::now()->addDay(),
            'rate_type' => 'daily', 'rate' => 10,
        ]);

        Livewire::test(CustomerForm::class, ['id' => $customer->id])
            ->assertSee('Rental orders')
            ->assertSee($order->reference)   // their order shows on the customer page
            ->assertSee('203011')            // the car it was for
            ->assertSee('Total spend');
    }

    public function test_documents_uploaded_on_orders_appear_on_the_customer_page(): void
    {
        $customer = RentalCustomer::query()->create(['name' => 'Qassim', 'phone' => '38467744']);
        $car = Vehicle::query()->create(['name' => 'Eco Sport', 'daily_rate' => 10]);
        RentalOrder::query()->create([
            'customer_id' => $customer->id, 'vehicle_id' => $car->id,
            'start_date' => Carbon::now(), 'end_date' => Carbon::now()->addDay(),
            'rate_type' => 'daily', 'rate' => 10,
            'cpr_image_path' => 'rental_orders/qassim-cpr.jpg',
            'license_image_path' => 'rental_orders/qassim-licence.jpg',
        ]);

        Livewire::test(CustomerForm::class, ['id' => $customer->id])
            ->assertSee('Documents')
            ->assertSee('rental_orders/qassim-cpr.jpg')        // the CPR is reachable here
            ->assertSee('rental_orders/qassim-licence.jpg');   // and the licence
    }

    public function test_a_customer_with_no_history_shows_an_empty_state(): void
    {
        $customer = RentalCustomer::query()->create(['name' => 'New Person', 'phone' => '30000000']);

        Livewire::test(CustomerForm::class, ['id' => $customer->id])
            ->assertSee('No rental orders yet.');
    }

    public function test_saving_a_company_keeps_cr_and_contact_clears_individual_fields(): void
    {
        Livewire::test(CustomerForm::class)
            ->set('type', 'company')
            ->set('name', 'Wanaan Trading W.L.L.')
            ->set('country', 'BH')
            ->set('phone', '17000000')
            ->set('cr_number', '12345-1')
            ->set('contact_person', 'Qassim')
            ->set('contact_phone', '39000000')
            ->set('cpr', '999')   // should be cleared for a company
            ->call('save')
            ->assertHasNoErrors();

        $c = RentalCustomer::query()->where('name', 'Wanaan Trading W.L.L.')->sole();
        $this->assertSame('company', $c->type);
        $this->assertSame('12345-1', $c->cr_number);
        $this->assertSame('Qassim', $c->contact_person);
        $this->assertSame('39000000', $c->contact_phone);
        $this->assertSame('BH', $c->country);
        $this->assertNull($c->cpr);
    }

    public function test_saving_an_individual_keeps_cpr_clears_company_fields(): void
    {
        Livewire::test(CustomerForm::class)
            ->set('type', 'individual')
            ->set('name', 'Ali Hassan')
            ->set('cpr', '900112233')
            ->set('license_no', '880011')
            ->set('cr_number', 'X')  // should be cleared for an individual
            ->call('save')
            ->assertHasNoErrors();

        $c = RentalCustomer::query()->where('name', 'Ali Hassan')->sole();
        $this->assertSame('individual', $c->type);
        $this->assertSame('900112233', $c->cpr);
        $this->assertNull($c->cr_number);
    }

    public function test_a_company_cr_document_path_is_stored(): void
    {
        // The PDF is uploaded by the direct controller; the form keeps the path.
        Livewire::test(CustomerForm::class)
            ->set('type', 'company')
            ->set('name', 'Docs Co')
            ->set('crDocumentPath', 'rental_customers/cr.pdf')
            ->call('save')
            ->assertHasNoErrors();

        $c = RentalCustomer::query()->where('name', 'Docs Co')->sole();
        $this->assertSame('rental_customers/cr.pdf', $c->cr_document);
    }

    public function test_the_cr_upload_controller_accepts_a_pdf_into_the_customers_bucket(): void
    {
        Storage::fake('public');

        $response = $this->post(route('form.upload-file'), [
            'file' => UploadedFile::fake()->create('cr.pdf', 100, 'application/pdf'),
            'bucket' => 'rental_customers',
            'only' => 'pdf',
        ]);

        $response->assertOk();
        $path = $response->json('path');
        $this->assertIsString($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_a_non_super_admin_cannot_change_an_existing_customers_type(): void
    {
        // setUp acts as a plain admin (not super). The type is set at creation
        // and locked afterwards for everyone but a super-admin.
        $customer = RentalCustomer::query()->create(['name' => 'Acme Co', 'type' => 'company']);

        Livewire::test(CustomerForm::class, ['id' => $customer->id])
            ->set('type', 'individual')   // tampering attempt
            ->set('name', 'Acme Co')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('company', $customer->fresh()?->type); // unchanged
    }

    public function test_a_super_admin_can_change_an_existing_customers_type(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => true]));
        $customer = RentalCustomer::query()->create(['name' => 'Acme Co', 'type' => 'company']);

        Livewire::test(CustomerForm::class, ['id' => $customer->id])
            ->set('type', 'individual')
            ->set('name', 'Acme Co')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('individual', $customer->fresh()?->type);
    }

    public function test_the_flag_is_derived_from_the_country_code(): void
    {
        $this->assertSame('🇧🇭', RentalCustomer::flagFor('BH'));
        $this->assertSame('', RentalCustomer::flagFor(null));

        $c = RentalCustomer::query()->create(['name' => 'Flagged', 'country' => 'SA']);
        $this->assertSame('🇸🇦', $c->flag);
    }
}
