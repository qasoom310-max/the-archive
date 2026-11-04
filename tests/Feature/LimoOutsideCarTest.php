<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Erp\Settings\Setting;
use App\Mail\CustomerDocumentMail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Modules\Limousine\Livewire\Bookings;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Services\LimoQueueRows;
use Modules\Limousine\Services\TripCrew;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * A trip run in another company's car, driven by one of ours: the Assign
 * dialog takes the company, the car as typed and what we pay them, and the
 * trip then counts as having its car.
 */
final class LimoOutsideCarTest extends TestCase
{
    use DatabaseMigrations;

    private LimoLeg $leg;

    private LimoDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutDefer();
        Mail::fake();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');
        app(ModuleManager::class)->install('limousine');

        Setting::set('mail.customer_documents', true);
        Setting::set('company.customer_terms', '');

        $customer = LimoCustomer::query()->create(['name' => 'Ahmed Radwan', 'phone' => '39000000', 'email' => 'ahmed@example.com']);
        $booking = LimoBooking::query()->create(['customer_id' => $customer->id, 'status' => LimoBooking::STATUS_QUEUE]);
        $this->leg = LimoLeg::query()->create([
            'legable_type' => LimoBooking::class, 'legable_id' => $booking->id,
            'status' => LimoLeg::STATUS_QUEUE, 'start_at' => now()->addHours(6),
            'from_location' => 'Riyadh', 'to_location' => 'Qassim',
        ]);
        $this->driver = LimoDriver::query()->create(['name' => 'Habib Ali', 'phone' => '+973 3999 1234', 'active' => true]);
    }

    private function dialog(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(Bookings::class)
            ->call('openAssign', $this->leg->id)
            ->set('assignCarSource', 'outside')
            ->set('assignOutsideCompany', 'Al Saif Transport')
            ->set('assignOutsideCar', 'GMC Yukon, black, 45213')
            ->set('assignOutsideCost', '35')
            ->set('assignDriver', (string) $this->driver->id);
    }

    public function test_another_companys_car_with_our_driver_is_saved_and_counts_as_crewed(): void
    {
        $this->dialog()->call('saveAssign')->assertHasNoErrors();

        $leg = $this->leg->fresh();
        $this->assertNotNull($leg);
        $this->assertNull($leg->car_id);
        $this->assertSame('Al Saif Transport', $leg->outside_company);
        $this->assertSame('GMC Yukon, black, 45213', $leg->vehicle);
        $this->assertSame(35.0, $leg->outside_cost);
        $this->assertSame($this->driver->id, $leg->driver_id);
        $this->assertTrue($leg->hasCarAndDriver());
        // Naming the driver dispatches the trip, as with one of our cars.
        $this->assertSame(LimoLeg::STATUS_ACTIVE, $leg->status);
        $this->assertNull($leg->crewUrgency(now()));
    }

    public function test_the_company_and_the_car_are_required(): void
    {
        $this->dialog()
            ->set('assignOutsideCompany', '')
            ->set('assignOutsideCar', '')
            ->call('saveAssign')
            ->assertHasErrors(['assignOutsideCompany', 'assignOutsideCar']);

        $this->assertNull($this->leg->fresh()?->outside_company);
    }

    public function test_a_bad_cost_is_refused(): void
    {
        $this->dialog()->set('assignOutsideCost', 'abc')->call('saveAssign')->assertHasErrors('assignOutsideCost');
    }

    public function test_reopening_shows_it_and_switching_back_to_our_car_clears_it(): void
    {
        $this->dialog()->call('saveAssign');

        $car = Vehicle::query()->create(['name' => 'Ford Taurus', 'plate_no' => '11223', 'active' => true]);

        Livewire::test(Bookings::class)
            ->call('openAssign', $this->leg->id)
            ->assertSet('assignCarSource', 'outside')
            ->assertSet('assignOutsideCompany', 'Al Saif Transport')
            ->assertSet('assignOutsideCar', 'GMC Yukon, black, 45213')
            ->assertSet('assignOutsideCost', '35')
            ->set('assignCarSource', 'own')
            ->set('assignCar', (string) $car->id)
            ->call('saveAssign')
            ->assertHasNoErrors();

        $leg = $this->leg->fresh();
        $this->assertNotNull($leg);
        $this->assertSame($car->id, $leg->car_id);
        $this->assertNull($leg->outside_company);
        $this->assertNull($leg->outside_cost);
    }

    public function test_the_needs_car_and_driver_tab_no_longer_lists_it(): void
    {
        $rows = app(LimoQueueRows::class);
        $this->assertSame(1, $rows->needsCrewCount());

        $this->dialog()->call('saveAssign');

        $this->assertSame(0, $rows->needsCrewCount());
    }

    public function test_the_list_names_the_other_company(): void
    {
        $this->dialog()->call('saveAssign');

        Livewire::test(Bookings::class)
            ->set('tab', 'all')
            ->assertSee('GMC Yukon, black, 45213')
            ->assertSee('Other company: Al Saif Transport');
    }

    public function test_the_customer_is_told_the_car_as_typed(): void
    {
        $this->dialog()->call('saveAssign');

        Mail::assertSent(CustomerDocumentMail::class, function (CustomerDocumentMail $mail): bool {
            $facts = array_column($mail->document->facts, 1, 0);

            return $facts['Car'] === 'GMC Yukon, black, 45213'
                && ! isset($facts['Plate no.'])
                && $facts['Driver'] === 'Habib Ali';
        });
    }

    public function test_the_staff_app_keeps_the_other_companys_car_when_it_sends_no_car(): void
    {
        $this->dialog()->call('saveAssign');
        $leg = $this->leg->fresh();
        $this->assertNotNull($leg);

        // The app has no such choice: it sends the driver alone.
        $other = LimoDriver::query()->create(['name' => 'Sohail Khan', 'active' => true]);
        $result = app(TripCrew::class)->assign($leg, null, $other->id, null);

        $this->assertSame([], $result['errors']);
        $after = $leg->fresh();
        $this->assertNotNull($after);
        $this->assertSame('Al Saif Transport', $after->outside_company);
        $this->assertSame($other->id, $after->driver_id);
    }
}
