<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Modules\Limousine\Console\ReviewDuplicateTrips;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoReceipt;
use Tests\TestCase;

/**
 * The money-aware review shortlist. Unlike {@see LimoFindDuplicateTripsTest},
 * this never treats "accidental-looking" as a confirmed defect — a group
 * with an invoice or receipt already attached to one side is flagged apart
 * from a group with nothing recorded, because the first is almost certainly
 * two genuinely separate, separately-billed trips.
 */
final class LimoReviewDuplicateTripsTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
        $this->registerCommand();
    }

    private function registerCommand(): void
    {
        $kernel = $this->app?->make(Kernel::class);
        \assert($kernel instanceof ConsoleKernel);
        $kernel->registerCommand(new ReviewDuplicateTrips());
    }

    private function trip(string $customerName, Carbon $pickupAt, float $fare): LimoBooking
    {
        $customer = LimoCustomer::query()->firstOrCreate(['name' => $customerName]);

        $booking = LimoBooking::query()->create([
            'customer_id' => $customer->id,
            'pickup_at' => $pickupAt,
            'fare' => $fare,
            'amount' => $fare,
            'status' => LimoBooking::STATUS_COMPLETED,
            'payment_status' => LimoBooking::PAYMENT_PAID,
        ]);

        LimoLeg::query()->create([
            'legable_type' => LimoBooking::class,
            'legable_id' => $booking->id,
            'sequence' => 1,
            'start_at' => $pickupAt,
            'from_location' => 'Bahrain Airport',
            'to_location' => 'Seef',
            'rate' => $fare,
            'net_amount' => $fare,
        ]);

        return $booking;
    }

    public function test_a_clean_database_reports_nothing_to_review(): void
    {
        $this->trip('Ahmed', Carbon::parse('2026-06-01 10:00'), 45.0);
        $this->trip('Ahmed', Carbon::parse('2026-06-05 10:00'), 45.0);

        $this->artisan('limo:review-duplicate-trips')
            ->expectsOutputToContain('No accidental-looking duplicate groups to review.')
            ->assertSuccessful();
    }

    public function test_an_accidental_looking_group_with_no_money_is_the_strongest_candidate(): void
    {
        $this->trip('Sara', Carbon::parse('2026-06-01 10:00:00'), 45.0);
        $this->trip('Sara', Carbon::parse('2026-06-01 10:00:00'), 45.0);

        $this->artisan('limo:review-duplicate-trips')
            ->expectsOutputToContain('1 accidental-looking group(s) to review')
            ->expectsOutputToContain('with NO invoice or receipt on either side')
            ->doesntExpectOutputToContain('money is ALREADY recorded')
            ->assertSuccessful();
    }

    public function test_a_group_with_an_invoice_on_one_side_is_flagged_not_treated_as_clean(): void
    {
        $a = $this->trip('Mona', Carbon::parse('2026-06-01 10:00:00'), 45.0);
        $this->trip('Mona', Carbon::parse('2026-06-01 10:00:00'), 45.0);

        LimoInvoice::query()->create([
            'customer_id' => $a->customer_id, 'booking_id' => $a->id, 'issue_date' => now(), 'total' => 45,
        ]);

        $this->artisan('limo:review-duplicate-trips')
            ->expectsOutputToContain('1 accidental-looking group(s) to review')
            ->expectsOutputToContain('money is ALREADY recorded')
            ->expectsOutputToContain('money recorded on: ' . $a->reference)
            ->expectsOutputToContain('No accidental-looking group is entirely free of recorded money.')
            ->assertSuccessful();
    }

    public function test_a_group_with_a_receipt_on_one_side_is_also_flagged(): void
    {
        $a = $this->trip('Layla', Carbon::parse('2026-06-01 10:00:00'), 45.0);
        $this->trip('Layla', Carbon::parse('2026-06-01 10:00:00'), 45.0);

        LimoReceipt::query()->create([
            'booking_id' => $a->id, 'customer_id' => $a->customer_id, 'date' => now(), 'amount' => 45, 'method' => 'cash',
        ]);

        $this->artisan('limo:review-duplicate-trips')
            ->expectsOutputToContain('money is ALREADY recorded')
            ->expectsOutputToContain('money recorded on: ' . $a->reference)
            ->assertSuccessful();
    }

    /**
     * A wedding or company outing books many real cars under one customer
     * account — {@see LimoFindDuplicateTripsTest} pins that this is excluded
     * from the "accidental-looking" bucket entirely, and this shortlist must
     * never resurrect it just because it also checks money.
     */
    public function test_a_legitimate_multi_vehicle_group_never_appears_in_the_review(): void
    {
        $customer = LimoCustomer::query()->firstOrCreate(['name' => 'Jain Wedding']);

        foreach (['Priyanka Guru Prasad', 'Prathviraj Shastry'] as $pax) {
            $booking = LimoBooking::query()->create([
                'customer_id' => $customer->id,
                'pickup_at' => Carbon::parse('2026-06-01 10:00:00'),
                'fare' => 32.0,
                'amount' => 32.0,
                'pax_name' => $pax,
                'status' => LimoBooking::STATUS_COMPLETED,
                'payment_status' => LimoBooking::PAYMENT_PAID,
            ]);
            LimoLeg::query()->create([
                'legable_type' => LimoBooking::class,
                'legable_id' => $booking->id,
                'sequence' => 1,
                'start_at' => Carbon::parse('2026-06-01 10:00:00'),
                'from_location' => 'Bahrain Airport',
                'to_location' => 'Seef',
                'rate' => 32.0,
                'net_amount' => 32.0,
            ]);
        }

        $this->artisan('limo:review-duplicate-trips')
            ->expectsOutputToContain('No accidental-looking duplicate groups to review.')
            ->assertSuccessful();
    }

    public function test_it_writes_nothing_at_all(): void
    {
        $a = $this->trip('Yara', Carbon::parse('2026-06-01 10:00:00'), 45.0);
        $this->trip('Yara', Carbon::parse('2026-06-01 10:00:00'), 45.0);
        LimoInvoice::query()->create([
            'customer_id' => $a->customer_id, 'booking_id' => $a->id, 'issue_date' => now(), 'total' => 45,
        ]);

        $bookingsBefore = LimoBooking::query()->count();
        $legsBefore = LimoLeg::query()->count();
        $invoicesBefore = LimoInvoice::query()->count();
        $receiptsBefore = LimoReceipt::query()->count();

        $this->artisan('limo:review-duplicate-trips')->assertSuccessful();

        $this->assertSame($bookingsBefore, LimoBooking::query()->count());
        $this->assertSame($legsBefore, LimoLeg::query()->count());
        $this->assertSame($invoicesBefore, LimoInvoice::query()->count());
        $this->assertSame($receiptsBefore, LimoReceipt::query()->count());
    }
}
