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
use Modules\Limousine\Models\LimoLeg;
use Tests\TestCase;

/**
 * Chauffeur (hours) bookings: a car and driver held for N hours a day across N
 * days, rather than a one-way transfer. Reported as failing outright, so this
 * drives the whole path — form → save → queue → trip message.
 */
final class LimoChauffeurTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    public function test_a_chauffeur_booking_can_be_created(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Helen Friberg']);

        Livewire::test(BookingForm::class)
            ->set('customer_id', $customer->id)
            ->set('pax_name', 'Helen')
            ->set('requested_by', 'Office')
            ->set('legs.0.service_type', LimoLeg::TYPE_CHAUFFEUR)
            ->set('legs.0.from_location', 'Manama')
            ->set('legs.0.start_at', now()->addDays(2)->format('Y-m-d\TH:i'))
            ->set('legs.0.hours', '8')
            ->set('legs.0.days', '3')
            ->set('legs.0.rate', '20')
            ->set('legs.0.car_details', 'Sedan')
            ->set('legs.0.rate_basis', LimoLeg::BASIS_HOUR)
            ->call('save')
            ->assertHasNoErrors();

        $leg = LimoLeg::query()->firstOrFail();
        $this->assertSame(LimoLeg::TYPE_CHAUFFEUR, $leg->service_type);
        $this->assertSame(8.0, $leg->hours);
        $this->assertSame(3, $leg->days);
        // 20/hour × 8 hours × 3 days.
        $this->assertSame(480.0, $leg->net_amount);
        // A chauffeur leg has no drop-off, by design.
        $this->assertNull($leg->to_location);
    }

    public function test_the_queue_renders_a_chauffeur_trip(): void
    {
        $this->chauffeurLeg();

        Livewire::test(Bookings::class)->assertOk()->assertSee('Manama');
    }

    public function test_the_booking_form_reopens_a_chauffeur_trip(): void
    {
        $leg = $this->chauffeurLeg();

        Livewire::test(BookingForm::class, ['id' => $leg->legable_id])
            ->assertOk()
            ->assertSet('legs.0.service_type', LimoLeg::TYPE_CHAUFFEUR)
            ->assertSet('legs.0.hours', '8');
    }

    public function test_a_chauffeur_trip_produces_a_whatsapp_message(): void
    {
        $leg = $this->chauffeurLeg();

        $text = app(\Modules\Limousine\Services\LimoQueueRows::class)->whatsappText($leg);

        $this->assertStringContainsString('Manama', $text);
        // No drop-off line, since a chauffeur job has none.
        $this->assertStringNotContainsString('Drop off:', $text);
    }

    /**
     * The office does not fill a form in tidy order: they switch the type, clear
     * a box, retype it. Every one of those keystrokes re-renders the leg, so the
     * render has to survive half-filled input, not only the finished state.
     */
    public function test_a_half_filled_chauffeur_leg_still_renders(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Helen Friberg']);

        $c = Livewire::test(BookingForm::class)
            ->set('customer_id', $customer->id)
            ->set('legs.0.from_location', 'Manama')
            ->set('legs.0.to_location', 'Riffa')
            ->set('legs.0.start_at', now()->addDay()->format('Y-m-d\TH:i'));

        // Transfer → chauffeur, the way the toggle button does it.
        $c->set('legs.0.service_type', LimoLeg::TYPE_CHAUFFEUR)->assertOk();

        // Emptying the boxes one at a time.
        $c->set('legs.0.hours', '')->assertOk();
        $c->set('legs.0.days', '')->assertOk();
        $c->set('legs.0.rate', '')->assertOk();
        $c->set('legs.0.rate_basis', LimoLeg::BASIS_HOUR)->assertOk();
        $c->set('legs.0.hours', '8')->assertOk();
        $c->set('legs.0.days', '2')->assertOk();

        // And a second chauffeur leg alongside the first.
        $c->call('addLeg')
            ->set('legs.1.service_type', LimoLeg::TYPE_CHAUFFEUR)
            ->assertOk();
    }

    /** A stored chauffeur leg with no hours yet must not break the form. */
    public function test_a_chauffeur_leg_without_hours_renders(): void
    {
        $leg = $this->chauffeurLeg();
        $leg->forceFill(['hours' => null])->save();

        Livewire::test(BookingForm::class, ['id' => $leg->legable_id])->assertOk();
        Livewire::test(Bookings::class)->assertOk();
    }

    /**
     * A chauffeur leg draws a day-by-day schedule from its start date, and the
     * date box is live — so the server re-renders on every keystroke, with
     * whatever half-typed value the browser has at that instant. None of those
     * may take the page down.
     *
     * @dataProvider halfTypedDates
     */
    public function test_a_half_typed_start_date_does_not_break_the_chauffeur_form(string $typed): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Helen Friberg']);

        Livewire::test(BookingForm::class)
            ->set('customer_id', $customer->id)
            ->set('legs.0.service_type', LimoLeg::TYPE_CHAUFFEUR)
            ->set('legs.0.hours', '8')
            ->set('legs.0.start_at', $typed)
            ->assertOk();
    }

    /** @return array<string, array{string}> */
    public static function halfTypedDates(): array
    {
        return [
            'year still empty' => ['0000-08-31T10:00'],
            'day not typed yet' => ['2026-08-00T10:00'],
            'month not typed yet' => ['2026-00-31T10:00'],
            'nothing but separators' => ['--T:'],
            'not a date at all' => ['not a date'],
        ];
    }

    private function chauffeurLeg(): LimoLeg
    {
        $customer = LimoCustomer::query()->create(['name' => 'Helen Friberg']);

        $booking = LimoBooking::query()->create([
            'reference' => 'BK/00001',
            'customer_id' => $customer->id,
            'pickup_at' => now()->addDays(2),
            'status' => LimoBooking::STATUS_QUEUE,
        ]);

        $leg = LimoLeg::query()->create([
            'legable_type' => LimoBooking::class,
            'legable_id' => $booking->id,
            'sequence' => 0,
            'status' => LimoLeg::STATUS_QUEUE,
            'service_type' => LimoLeg::TYPE_CHAUFFEUR,
            'from_location' => 'Manama',
            'start_at' => now()->addDays(2),
            'hours' => 8,
            'days' => 3,
            'rate' => 20,
            'rate_basis' => LimoLeg::BASIS_HOUR,
            'net_amount' => 480,
        ]);

        return $leg->refresh();
    }
}
