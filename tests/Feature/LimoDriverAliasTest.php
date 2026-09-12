<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\Auth\ModelAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\DriverAliases as DriverAliasesPage;
use Modules\Limousine\Livewire\LimoEarnings;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoDriverAlias;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Support\DriverAliases;
use Modules\Rental\Services\DriverJobHistory;
use Tests\TestCase;

/**
 * Reading the old system's driver logins as people.
 *
 * Trips carried over name their driver in TEXT, and that text is the previous
 * system's login: "kown", "smakhlooq", "admin". The earnings league was
 * therefore ranking logins, with the office's own account near the top of it,
 * and a real driver's job history could not find his own older trips.
 *
 * These tests hold the answer: a login says who it was, the trips are never
 * rewritten, and a login nobody has decided on still reads exactly as it did.
 */
final class LimoDriverAliasTest extends TestCase
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

    /** The earnings desk is the owner's alone, so its assertions sign in as him. */
    private function owner(): User
    {
        return User::factory()->create(['is_admin' => true, 'is_super_admin' => true, 'name' => 'Wanaan']);
    }

    private function customer(): LimoCustomer
    {
        return LimoCustomer::query()->create(['name' => 'Ghazwan']);
    }

    /**
     * A booking of one trip, driven by whatever name the old system wrote.
     */
    private function trip(string $driver, float $fare = 45, string $payment = LimoBooking::PAYMENT_PAID, ?int $driverId = null): LimoLeg
    {
        static $n = 0;
        $n++;

        $booking = LimoBooking::query()->create([
            'reference' => 'BK/' . str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'customer_id' => $this->customer()->id,
            'pickup_at' => now()->startOfYear()->addDays($n),
            'status' => LimoBooking::STATUS_COMPLETED,
            'advance' => $payment === LimoBooking::PAYMENT_PAID ? $fare : 0,
            'payment_status' => $payment,
            'prepared_by' => 'Office',
        ]);

        $leg = LimoLeg::query()->create([
            'legable_type' => LimoBooking::class,
            'legable_id' => $booking->id,
            'sequence' => 0,
            'reference' => (string) (20000 + $n),
            'status' => LimoLeg::STATUS_COMPLETED,
            'start_at' => now()->startOfYear()->addDays($n),
            'from_location' => 'Bahrain Airport',
            'to_location' => 'Khobar',
            'rate' => $fare,
            'net_amount' => $fare,
            'driver' => $driver,
            'driver_id' => $driverId,
        ]);

        $booking->recalcTotal();
        $booking->save();

        return $leg;
    }

    /* ── The screen that asks the question ───────────────────────────────── */

    public function test_every_name_on_a_trip_is_offered_with_the_work_behind_it(): void
    {
        $this->trip('kown', 45);
        $this->trip('kown', 55);
        $this->trip('admin', 30);

        $rows = app(DriverAliases::class)->candidates();

        $this->assertCount(2, $rows);
        // Heaviest first, because a name carrying more trips distorts more.
        $this->assertSame('kown', $rows[0]['key']);
        $this->assertSame(2, $rows[0]['trips']);
        $this->assertSame(100.0, $rows[0]['collected']);
        $this->assertFalse($rows[0]['decided']);
    }

    /** Two spellings of one login are one question, not two. */
    public function test_the_same_name_in_two_spellings_is_one_row(): void
    {
        $this->trip('habib');
        $this->trip('Habib');
        $this->trip('  HABIB ');

        $rows = app(DriverAliases::class)->candidates();

        $this->assertCount(1, $rows);
        $this->assertSame('habib', $rows[0]['key']);
        $this->assertSame(3, $rows[0]['trips']);
    }

    public function test_the_page_lists_the_names_still_to_decide(): void
    {
        $this->trip('kown');
        $this->trip('smakhlooq');

        Livewire::test(DriverAliasesPage::class)
            ->assertOk()
            ->assertSee('kown')
            ->assertSee('smakhlooq');
    }

    /* ── Saying who they were ────────────────────────────────────────────── */

    public function test_matching_a_login_to_a_driver_renames_him_in_the_earnings(): void
    {
        $driver = LimoDriver::query()->create(['name' => 'Hussain Makhlooq']);
        $this->trip('kown', 45);

        $page = Livewire::test(DriverAliasesPage::class);
        $slug = app(DriverAliases::class)->candidates()[0]['slug'];

        $page->set('choices.' . $slug, (string) $driver->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Hussain Makhlooq', app(DriverAliases::class)->resolve('kown')['name']);

        $this->actingAs($this->owner());

        Livewire::test(LimoEarnings::class)
            ->assertSee('Hussain Makhlooq')
            ->assertDontSee('kown');
    }

    public function test_a_login_marked_as_the_office_leaves_the_league(): void
    {
        $this->trip('admin', 30);
        $this->trip('habib', 45);

        $service = app(DriverAliases::class);
        $rows = $service->candidates();
        $adminSlug = collect($rows)->firstWhere('key', 'admin')['slug'];

        Livewire::test(DriverAliasesPage::class)
            ->set('choices.' . $adminSlug, DriverAliasesPage::OFFICE)
            ->call('save')
            ->assertHasNoErrors();

        $resolved = app(DriverAliases::class)->resolve('admin');
        $this->assertTrue($resolved['office']);

        // Counted apart rather than ranked as a person, and said so on the page.
        $this->actingAs($this->owner());

        Livewire::test(LimoEarnings::class)
            ->assertDontSee('admin')
            ->assertSee('1 trips are on an office or system account');
    }

    /** The trips themselves are never touched — that is the whole safety of it. */
    public function test_saving_a_match_does_not_rewrite_a_single_trip(): void
    {
        $driver = LimoDriver::query()->create(['name' => 'Hussain Makhlooq']);
        $leg = $this->trip('kown');

        $slug = app(DriverAliases::class)->candidates()[0]['slug'];

        Livewire::test(DriverAliasesPage::class)
            ->set('choices.' . $slug, (string) $driver->id)
            ->call('save');

        $fresh = $leg->fresh();
        $this->assertSame('kown', $fresh?->driver);
        $this->assertNull($fresh?->driver_id);
    }

    /** A match made wrongly is a match changed, not a history to repair. */
    public function test_a_match_can_be_changed_afterwards(): void
    {
        $wrong = LimoDriver::query()->create(['name' => 'Wrong Person']);
        $right = LimoDriver::query()->create(['name' => 'Right Person']);
        $this->trip('kown');

        $slug = app(DriverAliases::class)->candidates()[0]['slug'];

        Livewire::test(DriverAliasesPage::class)
            ->set('choices.' . $slug, (string) $wrong->id)->call('save');
        $this->assertSame('Wrong Person', app(DriverAliases::class)->resolve('kown')['name']);

        Livewire::test(DriverAliasesPage::class)
            ->set('choices.' . $slug, (string) $right->id)->call('save');
        $this->assertSame('Right Person', app(DriverAliases::class)->resolve('kown')['name']);

        $this->assertSame(1, LimoDriverAlias::query()->where('alias', 'kown')->count());
    }

    /** An unanswered question keeps reading as itself. */
    public function test_a_login_nobody_has_decided_on_is_shown_as_it_was(): void
    {
        $this->trip('qmakki');

        $resolved = app(DriverAliases::class)->resolve('qmakki');

        $this->assertSame('qmakki', $resolved['name']);
        $this->assertNull($resolved['driverId']);
        $this->assertFalse($resolved['office']);

        $this->actingAs($this->owner());

        Livewire::test(LimoEarnings::class)->assertSee('Qmakki');
    }

    /* ── What the match is worth ─────────────────────────────────────────── */

    /**
     * The reason this matters most: a driver's own history stopped at the day
     * the ERP went live, because his older trips only ever named a login.
     */
    public function test_a_matched_driver_gets_his_older_trips_back(): void
    {
        $driver = LimoDriver::query()->create(['name' => 'Habib Ali']);
        $this->trip('habib');
        $this->trip('habib');
        $this->trip('Habib Ali', driverId: (int) $driver->id);

        // Before the match: only the trip that points at his record.
        $this->assertCount(1, app(DriverJobHistory::class)->for((int) $driver->id));

        LimoDriverAlias::query()->create(['alias' => 'habib', 'driver_id' => $driver->id]);

        $this->assertCount(3, app(DriverJobHistory::class)->for((int) $driver->id));
    }

    public function test_a_driver_keeps_only_his_own_old_trips(): void
    {
        $habib = LimoDriver::query()->create(['name' => 'Habib Ali']);
        $sali = LimoDriver::query()->create(['name' => 'Sali Hasan']);
        $this->trip('habib');
        $this->trip('sali');

        LimoDriverAlias::query()->create(['alias' => 'habib', 'driver_id' => $habib->id]);
        LimoDriverAlias::query()->create(['alias' => 'sali', 'driver_id' => $sali->id]);

        $this->assertCount(1, app(DriverJobHistory::class)->for((int) $habib->id));
        $this->assertCount(1, app(DriverJobHistory::class)->for((int) $sali->id));
    }

    /** The queue shows the person, not the login he was recorded under. */
    public function test_the_queue_shows_the_matched_driver(): void
    {
        $driver = LimoDriver::query()->create(['name' => 'Hussain Makhlooq']);
        $this->trip('smakhlooq');

        LimoDriverAlias::query()->create(['alias' => 'smakhlooq', 'driver_id' => $driver->id]);

        $rows = app(\Modules\Limousine\Services\LimoQueueRows::class);
        $leg = LimoLeg::query()->sole();

        $this->assertSame('Hussain Makhlooq', $rows->row($leg)['driver']);
    }

    /* ── The guess ───────────────────────────────────────────────────────── */

    /** An initial welded to a surname is how the old logins were built. */
    public function test_a_login_built_from_initial_and_surname_is_guessed(): void
    {
        LimoDriver::query()->create(['name' => 'Hussain Makhlooq']);
        $this->trip('hmakhlooq');

        $row = app(DriverAliases::class)->candidates()[0];

        $this->assertNotNull($row['suggestion']);
    }

    /** Two people who would produce the same login make it mean nothing. */
    public function test_nothing_is_guessed_when_two_drivers_would_answer(): void
    {
        LimoDriver::query()->create(['name' => 'Ali Hasan']);
        LimoDriver::query()->create(['name' => 'Ali Mansoor']);
        $this->trip('ali');

        $this->assertNull(app(DriverAliases::class)->candidates()[0]['suggestion']);
    }

    /** A guess is offered, never saved on its own. */
    public function test_a_guess_is_not_a_decision(): void
    {
        LimoDriver::query()->create(['name' => 'Hussain Makhlooq']);
        $this->trip('hmakhlooq');

        Livewire::test(DriverAliasesPage::class)->assertOk();

        $this->assertSame(0, LimoDriverAlias::query()->count());
        $this->assertSame('hmakhlooq', app(DriverAliases::class)->resolve('hmakhlooq')['name']);
    }

    /* ── Who may decide ──────────────────────────────────────────────────── */

    public function test_someone_who_may_only_look_cannot_save_a_match(): void
    {
        $driver = LimoDriver::query()->create(['name' => 'Hussain Makhlooq']);
        $this->trip('kown');
        $slug = app(DriverAliases::class)->candidates()[0]['slug'];

        // Read on the driver register, and nothing else: deciding who a login
        // was is a change to the register, not a look at it.
        ModelAccess::query()->create([
            'model' => 'limousine.driver', 'group_id' => null, 'name' => 'test:read-only',
            'perm_read' => true, 'perm_write' => false, 'perm_create' => false, 'perm_unlink' => false,
        ]);
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        Livewire::test(DriverAliasesPage::class)
            ->set('choices.' . $slug, (string) $driver->id)
            ->call('save')
            ->assertForbidden();

        $this->assertSame(0, LimoDriverAlias::query()->count());
    }

    public function test_a_user_with_no_access_at_all_cannot_open_the_page(): void
    {
        $this->trip('kown');
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        Livewire::test(DriverAliasesPage::class)->assertForbidden();
    }
}
