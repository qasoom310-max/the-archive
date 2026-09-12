<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Limousine\Console\MatchDriverNames;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoDriverAlias;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Support\DriverAliases;
use Tests\TestCase;

/**
 * The system says who the old logins were, rather than asking the owner to.
 *
 * A hundred and twelve names is not a question, it is a job — and handing the
 * owner a job the records can mostly answer is the wrong way round. The command
 * answers what it can from what is already known and names what it cannot.
 *
 * Deciding automatically is only safe because nothing is rewritten: a wrong
 * answer here costs one click on the matching screen, not a history.
 */
final class LimoMatchDriverNamesTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true, 'name' => 'Qassim']));
        $modules = app(ModuleManager::class);
        $modules->install('limousine');
        $modules->install('rental');
    }

    private function trip(string $driver): LimoLeg
    {
        static $n = 0;
        $n++;

        $booking = LimoBooking::query()->create([
            'reference' => 'BK/' . str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'customer_id' => LimoCustomer::query()->create(['name' => 'Ghazwan'])->id,
            'pickup_at' => now()->startOfYear()->addDays($n),
            'status' => LimoBooking::STATUS_COMPLETED,
            'payment_status' => LimoBooking::PAYMENT_PAID,
            'advance' => 45,
        ]);

        return LimoLeg::query()->create([
            'legable_type' => LimoBooking::class,
            'legable_id' => $booking->id,
            'sequence' => 0,
            'reference' => (string) (30000 + $n),
            'status' => LimoLeg::STATUS_COMPLETED,
            'start_at' => now()->startOfYear()->addDays($n),
            'from_location' => 'Bahrain Airport',
            'to_location' => 'Khobar',
            'rate' => 45, 'net_amount' => 45,
            'driver' => $driver,
        ]);
    }

    /**
     * A module command registers on the boot AFTER its module is installed, and
     * the module is installed in setUp — so the test adds it by hand, the same
     * way the rental import tests do.
     */
    private function registerCommand(): void
    {
        $kernel = $this->app?->make(Kernel::class);
        \assert($kernel instanceof ConsoleKernel);
        $kernel->registerCommand(new MatchDriverNames());
    }

    private function matchNames(): void
    {
        $this->registerCommand();
        $this->artisan('limo:match-driver-names')->assertSuccessful();
        app(DriverAliases::class)->flush();
    }

    /** An initial welded to a surname is how these logins were built. */
    public function test_a_login_that_only_one_driver_could_have_produced_is_matched(): void
    {
        LimoDriver::query()->create(['name' => 'Hussain Makhlooq']);
        $this->trip('hmakhlooq');

        $this->matchNames();

        $this->assertSame('Hussain Makhlooq', app(DriverAliases::class)->resolve('hmakhlooq')['name']);
    }

    /**
     * The Mariam case, which is what this rule exists for.
     *
     * Mariam entered 1,581 bookings in the old system and is named as the
     * driver on 1,014 trips. If the register happens to hold a driver whose
     * FIRST name is Mariam, half a name is not enough to say they are the same
     * person — and the office noticing it in a report is the wrong way to find
     * out. It is left for someone to say.
     */
    public function test_half_a_name_does_not_beat_the_office_check(): void
    {
        LimoDriver::query()->create(['name' => 'Mariam Hasan']);
        User::query()->create([
            'name' => 'Mariam', 'email' => 'mariam@wanaan-bh.com', 'password' => bcrypt('x'),
        ]);
        $this->trip('mariam');

        $this->matchNames();

        $resolved = app(DriverAliases::class)->resolve('mariam');
        $this->assertSame('mariam', $resolved['name'], 'half a name should not have decided this');
        $this->assertFalse($resolved['office']);
    }

    /** A whole name still decides it, office login or not. */
    public function test_a_whole_name_still_decides_it(): void
    {
        LimoDriver::query()->create(['name' => 'Mariam Hasan']);
        User::query()->create([
            'name' => 'Mariam', 'email' => 'mariam@wanaan-bh.com', 'password' => bcrypt('x'),
        ]);
        $this->trip('mariam hasan');

        $this->matchNames();

        $this->assertSame('Mariam Hasan', app(DriverAliases::class)->resolve('mariam hasan')['name']);
    }

    /** A first name alone is left alone even when nobody signs in as it. */
    public function test_a_first_name_alone_is_offered_but_not_decided(): void
    {
        LimoDriver::query()->create(['name' => 'Habib Ali']);
        $this->trip('habib');

        $this->matchNames();

        $this->assertSame('habib', app(DriverAliases::class)->resolve('habib')['name']);

        // …but the screen still offers it, marked as only half a match.
        $row = app(DriverAliases::class)->candidates()[0];
        $this->assertNotNull($row['suggestion']);
        $this->assertFalse($row['suggestionStrong']);
    }

    /**
     * The machine may take back its own answer when its rules improve. A run
     * under the old rule matched "mariam" on a first name; this run withdraws it.
     */
    public function test_it_takes_back_its_own_answer_under_a_better_rule(): void
    {
        $driver = LimoDriver::query()->create(['name' => 'Mariam Hasan']);
        User::query()->create([
            'name' => 'Mariam', 'email' => 'mariam@wanaan-bh.com', 'password' => bcrypt('x'),
        ]);
        $this->trip('mariam');

        // What the looser rule wrote.
        LimoDriverAlias::query()->create([
            'alias' => 'mariam', 'driver_id' => $driver->id, 'auto' => true,
            'decided_by' => 'Matched automatically',
        ]);

        $this->matchNames();

        $this->assertSame('mariam', app(DriverAliases::class)->resolve('mariam')['name']);
        $this->assertNull(LimoDriverAlias::query()->where('alias', 'mariam')->sole()->driver_id);
    }

    /** But a PERSON's answer is never taken back, however the rules change. */
    public function test_it_never_takes_back_a_persons_answer(): void
    {
        $driver = LimoDriver::query()->create(['name' => 'Mariam Hasan']);
        User::query()->create([
            'name' => 'Mariam', 'email' => 'mariam@wanaan-bh.com', 'password' => bcrypt('x'),
        ]);
        $this->trip('mariam');

        // The office says: yes, our Mariam really does drive.
        LimoDriverAlias::query()->create([
            'alias' => 'mariam', 'driver_id' => $driver->id, 'auto' => false, 'decided_by' => 'Qassim',
        ]);

        $this->matchNames();

        $this->assertSame('Mariam Hasan', app(DriverAliases::class)->resolve('mariam')['name']);
    }
    /** Two people who could both answer to it mean it answers to neither. */
    public function test_an_ambiguous_login_is_left_for_a_person_to_say(): void
    {
        LimoDriver::query()->create(['name' => 'Ali Hasan']);
        LimoDriver::query()->create(['name' => 'Ali Mansoor']);
        $this->trip('ali');

        $this->matchNames();

        $this->assertSame(0, LimoDriverAlias::query()->count());
        $this->assertSame('ali', app(DriverAliases::class)->resolve('ali')['name']);
    }

    /** The old system's own logins are not people. */
    public function test_the_catch_all_and_the_machine_logins_become_the_office(): void
    {
        foreach (['admin', 'via', 'apiuser', 'mac', 'asprinter', 'fone rent', 'p'] as $login) {
            $this->trip($login);
        }

        $this->matchNames();

        foreach (['admin', 'via', 'apiuser', 'mac', 'asprinter', 'fone rent', 'p'] as $login) {
            $this->assertTrue(
                app(DriverAliases::class)->resolve($login)['office'],
                $login . ' should have been read as the office.',
            );
        }
    }

    /**
     * Somebody who signs in here and is not in the driver register was booking
     * the trip, not driving it.
     */
    public function test_a_login_belonging_to_a_user_of_this_system_becomes_the_office(): void
    {
        User::query()->create([
            'name' => 'Mariam', 'email' => 'mariam@wanaan-bh.com', 'password' => bcrypt('x'),
        ]);
        $this->trip('mariam');

        $this->matchNames();

        $this->assertTrue(app(DriverAliases::class)->resolve('mariam')['office']);
    }

    /** An e-mail is a login too: qmakki@… is "qmakki" on a trip. */
    public function test_the_part_before_the_at_sign_counts_as_a_login(): void
    {
        User::query()->create([
            'name' => 'Qassim Makki', 'email' => 'qmakki@wanaan-bh.com', 'password' => bcrypt('x'),
        ]);
        $this->trip('qmakki');

        $this->matchNames();

        $this->assertTrue(app(DriverAliases::class)->resolve('qmakki')['office']);
    }

    /**
     * Someone can be both: a driver who also signs in is still a driver, because
     * the register is the list of people who drive.
     */
    public function test_a_driver_who_also_signs_in_here_stays_a_driver(): void
    {
        User::query()->create([
            'name' => 'Habib Ali', 'email' => 'habib@wanaan-bh.com', 'password' => bcrypt('x'),
        ]);
        LimoDriver::query()->create(['name' => 'Habib Ali']);
        $this->trip('habib ali');

        $this->matchNames();

        $resolved = app(DriverAliases::class)->resolve('habib ali');
        $this->assertFalse($resolved['office']);
        $this->assertSame('Habib Ali', $resolved['name']);
    }

    /** A person's answer is never overwritten by the machine's. */
    public function test_a_decision_already_made_is_left_alone(): void
    {
        $driver = LimoDriver::query()->create(['name' => 'Hussain Makhlooq']);
        $this->trip('admin');

        // Somebody has said that on THIS fleet "admin" really was a driver.
        LimoDriverAlias::query()->create(['alias' => 'admin', 'driver_id' => $driver->id]);

        $this->matchNames();

        $resolved = app(DriverAliases::class)->resolve('admin');
        $this->assertFalse($resolved['office']);
        $this->assertSame('Hussain Makhlooq', $resolved['name']);
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        LimoDriver::query()->create(['name' => 'Habib Ali']);
        $this->trip('habib ali');
        $this->trip('admin');

        $this->matchNames();
        $first = LimoDriverAlias::query()->orderBy('alias')->get(['alias', 'driver_id', 'is_office'])->toArray();

        $this->matchNames();
        $second = LimoDriverAlias::query()->orderBy('alias')->get(['alias', 'driver_id', 'is_office'])->toArray();

        $this->assertSame($first, $second);
        $this->assertCount(2, $second);
    }

    /** Nothing about a trip is touched, which is what makes this safe to run. */
    public function test_it_does_not_rewrite_a_single_trip(): void
    {
        LimoDriver::query()->create(['name' => 'Habib Ali']);
        $leg = $this->trip('habib');

        $this->matchNames();

        $fresh = $leg->fresh();
        $this->assertSame('habib', $fresh?->driver);
        $this->assertNull($fresh?->driver_id);
    }

    public function test_pretend_writes_nothing(): void
    {
        LimoDriver::query()->create(['name' => 'Habib Ali']);
        $this->trip('habib');

        $this->registerCommand();
        $this->artisan('limo:match-driver-names --pretend')->assertSuccessful();

        $this->assertSame(0, LimoDriverAlias::query()->count());
    }

    /** What it could not answer is named, not just counted. */
    public function test_it_names_what_it_could_not_answer(): void
    {
        $this->trip('hbusafwan');

        $this->registerCommand();

        $this->artisan('limo:match-driver-names')
            ->expectsOutputToContain('still unknown: hbusafwan')
            ->assertSuccessful();
    }
}
