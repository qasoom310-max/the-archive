<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Rental\Livewire\OrderForm;
use Modules\Rental\Livewire\RentalHome;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * Car papers (registration + insurance): a car only rents out while both are
 * valid; expired/missing papers hold it out of the booking list (super-admin
 * may override), and cars expiring within 30 days surface a renewal reminder.
 */
final class RentalCarDocumentsTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('rental');
    }

    private function car(string $name, ?int $regDays, ?int $insDays): Vehicle
    {
        return Vehicle::query()->create([
            'name' => $name,
            'daily_rate' => 10,
            'registration_expiry' => $regDays === null ? null : Carbon::today()->addDays($regDays),
            'insurance_expiry' => $insDays === null ? null : Carbon::today()->addDays($insDays),
        ]);
    }

    public function test_documents_valid_only_when_both_papers_are_present_and_future(): void
    {
        $this->assertTrue($this->car('Valid', 60, 90)->documentsValid());
        $this->assertFalse($this->car('NoReg', null, 90)->documentsValid());
        $this->assertFalse($this->car('Expired', -1, 90)->documentsValid());
        $this->assertFalse($this->car('Missing', null, null)->documentsValid());
    }

    public function test_expiring_soon_is_within_the_reminder_window_but_still_valid(): void
    {
        $this->assertTrue($this->car('Soon', 10, 200)->expiringSoon());   // 10 days out
        $this->assertFalse($this->car('Later', 200, 200)->expiringSoon()); // far off
        $this->assertFalse($this->car('Lapsed', -1, 200)->expiringSoon()); // already expired ≠ "soon"
    }

    public function test_bookable_scope_keeps_only_valid_cars(): void
    {
        $valid = $this->car('Valid', 60, 60);
        $this->car('Expired', -1, 60);
        $this->car('Missing', null, null);

        $ids = Vehicle::query()->bookable()->pluck('id')->all();
        $this->assertSame([$valid->id], $ids);
    }

    /**
     * Anyone who may raise orders — a supervisor, not only the owner — sees
     * every active car, a lapsed one flagged "papers expired". Hiding them
     * left supervisors with an empty picker on a fleet with no paper dates.
     */
    public function test_a_supervisor_sees_every_car_with_lapsed_ones_flagged(): void
    {
        $this->grantEveryone('rental.order');
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->car('BookableCarZ', 60, 60);
        $this->car('ExpiredCarZ', -1, 60);
        $this->car('NoPapersCarZ', null, null);

        Livewire::test(OrderForm::class)
            ->assertSee('BookableCarZ')
            ->assertSee('ExpiredCarZ')
            ->assertSee('NoPapersCarZ')
            ->assertSee('papers expired');
    }

    /**
     * Renewing the registration while the insurance has also lapsed left the
     * same vague warning up, which read as "my change was ignored". The form
     * now names the paper still at fault, and goes quiet once both are valid.
     */
    public function test_the_order_form_names_the_paper_still_at_fault(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => true]));
        $car = $this->car('TaurusZ', 300, -5);
        $expired = Carbon::today()->subDays(5)->format('Y-m-d');

        $form = Livewire::test(OrderForm::class)
            ->set('vehicle_id', $car->id)
            ->assertSee('Insurance expired on ' . $expired . '.')
            ->assertDontSee('Registration expired')
            ->assertSee('/app/rental/vehicle/' . $car->id);

        $car->update(['insurance_expiry' => Carbon::today()->addYear()]);
        $form->set('vehicle_id', $car->id)->assertDontSee('data-car-papers', false);

        $blank = $this->car('BlankZ', null, 300);
        $form->set('vehicle_id', $blank->id)->assertSee('Registration expiry date is not recorded.');
    }

    public function test_a_regular_admin_sees_cars_with_lapsed_papers(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => false]));
        $this->car('ExpiredCarZ', -1, 60);

        Livewire::test(OrderForm::class)->assertSee('ExpiredCarZ');
    }

    public function test_a_super_admin_may_still_pick_a_car_with_lapsed_papers(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => true]));
        $this->car('ExpiredCarZ', -1, 60);

        Livewire::test(OrderForm::class)->assertSee('ExpiredCarZ');
    }

    public function test_editing_an_order_keeps_its_now_expired_car_in_the_picker(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => false]));
        $car = $this->car('ExpiredCarZ', -1, 60);
        $order = RentalOrder::query()->create([
            'customer_id' => RentalCustomer::query()->create(['name' => 'Ali'])->id,
            'vehicle_id' => $car->id,
            'start_date' => Carbon::now()->addDay(),
            'end_date' => Carbon::now()->addDays(2),
        ]);

        Livewire::test(OrderForm::class, ['id' => $order->id])->assertSee('ExpiredCarZ');
    }

    public function test_home_reminder_lists_cars_expiring_soon_or_expired(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->car('SoonCarZ', 10, 200);
        $this->car('ExpiredCarZ', -1, 200);
        $this->car('FineCarZ', 300, 300);

        Livewire::test(RentalHome::class)
            ->assertSee('SoonCarZ')
            ->assertSee('ExpiredCarZ')
            ->assertDontSee('FineCarZ');
    }

    public function test_paper_reminders_ignore_outside_rented_in_cars(): void
    {
        // An outside (rented-in) car with lapsed papers is the owner's problem,
        // not ours — it must not appear on the dashboard reminder nor inflate
        // the "Cars need paper renewal" notification count.
        $this->actingAs($admin = User::factory()->create(['is_admin' => true]));
        $this->car('OwnedLapsedZ', -1, 200);
        Vehicle::query()->create([
            'name' => 'OutsideLapsedZ', 'daily_rate' => 10, 'is_outside' => true,
            'registration_expiry' => Carbon::today()->subDay(),
            'insurance_expiry' => Carbon::today()->subDay(),
        ]);

        Livewire::test(RentalHome::class)
            ->assertSee('OwnedLapsedZ')
            ->assertDontSee('OutsideLapsedZ');

        $renewal = collect(app(\Modules\Rental\Support\RentalNotifications::class)->for($admin))
            ->firstWhere('title', 'Cars need paper renewal');
        $this->assertNotNull($renewal);
        $this->assertSame('1 expired or expiring soon', $renewal->description);
    }

    /**
     * An uploaded paper is saved even when another field of the car holds a
     * value the form does not accept (an imported category or fuel type).
     * Auto-save used to refuse the whole form in silence, so the upload looked
     * saved and was gone after a reload.
     */
    public function test_an_uploaded_paper_is_kept_even_when_another_field_is_invalid(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $car = Vehicle::query()->create(['name' => 'FORD EXPEDITION', 'plate_no' => '125818']);
        \Illuminate\Support\Facades\DB::table('rental_vehicles')->where('id', $car->id)->update(['category' => 'SUV ', 'fuel_type' => 'Petrol']);

        Livewire::test(\App\Livewire\Views\FormView::class, ['model' => Vehicle::class, 'modelKey' => 'rental.vehicle', 'recordId' => $car->id])
            ->set('filePaths.registration_doc', 'rental_vehicles/reg-2026.pdf')
            ->set('filePaths.insurance_doc', 'rental_vehicles/ins-2026.pdf')
            // A key the form does not declare as a file is never written.
            ->set('filePaths.name', 'rental_vehicles/evil.pdf');

        $car->refresh();
        $this->assertSame('rental_vehicles/reg-2026.pdf', $car->registration_doc);
        $this->assertSame('rental_vehicles/ins-2026.pdf', $car->insurance_doc);
        $this->assertSame('FORD EXPEDITION', $car->name);
    }

    public function test_an_upload_still_needs_write_access(): void
    {
        $car = Vehicle::query()->create(['name' => 'Kia', 'plate_no' => '1']);
        $this->grantEveryone('rental.vehicle');
        // Read-only on cars: may open the form, may not attach a paper.
        \App\Models\Auth\ModelAccess::query()->where('model', 'rental.vehicle')->update(['perm_write' => false, 'perm_create' => false]);
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        Livewire::test(\App\Livewire\Views\FormView::class, ['model' => Vehicle::class, 'modelKey' => 'rental.vehicle', 'recordId' => $car->id])
            ->set('filePaths.registration_doc', 'rental_vehicles/x.pdf')
            ->assertForbidden();

        $this->assertNull($car->refresh()->registration_doc);
    }

    /**
     * A saved date must come back into its box when the car is reopened, and
     * must not move when another field is edited afterwards. The office works
     * three hours ahead of UTC, which is where the day used to slip.
     */
    public function test_a_saved_expiry_date_shows_again_and_never_drifts(): void
    {
        config(['app.timezone' => 'Asia/Bahrain']);
        date_default_timezone_set('Asia/Bahrain');

        try {
            $this->actingAs(User::factory()->create(['is_admin' => true]));
            $car = Vehicle::query()->create(['name' => 'FORD EXPEDITION', 'plate_no' => '125796', 'color' => 'BLACK']);
            $form = ['model' => Vehicle::class, 'modelKey' => 'rental.vehicle', 'recordId' => $car->id];

            Livewire::test(\App\Livewire\Views\FormView::class, $form)
                ->set('form.registration_expiry', '2027-05-01')
                ->set('form.insurance_expiry', '2027-06-01');

            // Reopened: the boxes hold the plain date a date input can show.
            $reopened = Livewire::test(\App\Livewire\Views\FormView::class, $form)
                ->assertSet('form.registration_expiry', '2027-05-01')
                ->assertSet('form.insurance_expiry', '2027-06-01');

            // Editing something else must leave both dates exactly where they were.
            $reopened->set('form.color', 'WHITE')->set('form.color', 'BLACK');

            $car->refresh();
            $this->assertSame('2027-05-01', $car->registration_expiry?->toDateString());
            $this->assertSame('2027-06-01', $car->insurance_expiry?->toDateString());
        } finally {
            config(['app.timezone' => 'UTC']);
            date_default_timezone_set('UTC');
        }
    }

    /**
     * A value the record already holds is never a reason to refuse a save.
     * An imported fuel type or category the list does not offer used to block
     * every edit of that car, with nothing on screen to say so.
     */
    public function test_a_value_the_car_already_holds_does_not_block_other_edits(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $car = Vehicle::query()->create(['name' => 'Kia', 'plate_no' => '7']);
        \Illuminate\Support\Facades\DB::table('rental_vehicles')->where('id', $car->id)->update(['fuel_type' => 'Petrol', 'category' => 'SUV ']);

        Livewire::test(\App\Livewire\Views\FormView::class, ['model' => Vehicle::class, 'modelKey' => 'rental.vehicle', 'recordId' => $car->id])
            ->set('form.registration_expiry', '2027-05-01')
            ->assertHasNoErrors()
            // A NEW value that is not on the list is still refused, and now says so.
            ->set('form.fuel_type', 'steam')
            ->assertHasErrors('form.fuel_type')
            ->assertSee('Not saved yet');

        $car->refresh();
        $this->assertSame('2027-05-01', $car->registration_expiry?->toDateString());
        $this->assertSame('Petrol', $car->fuel_type);
    }
}
