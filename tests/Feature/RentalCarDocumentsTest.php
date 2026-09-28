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
}
