<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Rental\Livewire\DepositRefunds;
use Modules\Rental\Livewire\RentalHome;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * The deposit refunds page lists every deposit still held — due now, inside
 * the hold, and on cars not back yet — for the people who settle deposits.
 */
final class RentalDepositRefundsPageTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('rental');
        $this->grantEveryone('rental.order');
    }

    /** @param  array<string, mixed>  $extra */
    private function order(string $customer, float $deposit, ?int $returnedDaysAgo, array $extra = []): RentalOrder
    {
        return RentalOrder::query()->create(array_merge([
            'customer_id' => RentalCustomer::query()->create(['name' => $customer])->id,
            'vehicle_id' => Vehicle::query()->create(['name' => 'Yaris', 'daily_rate' => 10])->id,
            'start_date' => Carbon::now()->subDays(30),
            'end_date' => $returnedDaysAgo !== null ? Carbon::now()->subDays($returnedDaysAgo) : Carbon::now()->addDays(3),
            'rate_type' => 'daily', 'rate' => 10, 'deposit' => $deposit,
            'state' => $returnedDaysAgo !== null ? RentalOrder::STATE_CLOSED : RentalOrder::STATE_ACTIVE,
            'returned_at' => $returnedDaysAgo !== null ? Carbon::now()->subDays($returnedDaysAgo) : null,
        ], $extra));
    }

    /**
     * @return array<string, array{rows: list<array<string, mixed>>, total: float}>
     */
    private function refundGroups(User $as): array
    {
        $this->actingAs($as);

        /** @var array<string, array{rows: list<array<string, mixed>>, total: float}> $groups */
        $groups = Livewire::test(DepositRefunds::class)->viewData('groups');

        return $groups;
    }

    /**
     * @param  array{rows: list<array<string, mixed>>, total: float}  $group
     * @return list<string>
     */
    private function names(array $group): array
    {
        return array_map(static fn (array $r): string => (string) $r['order']->customer?->name, $group['rows']);
    }

    public function test_each_held_deposit_lands_in_its_group_soonest_first(): void
    {
        $this->order('Due Old', 50, 20);
        $this->order('Due New', 100, 15);
        $this->order('Hold Late', 30, 2);
        $this->order('Hold Soon', 40, 10);
        $this->order('Still Out', 75, null);

        $groups = $this->refundGroups(User::factory()->create(['is_accountant' => true]));

        $this->assertSame(['Due Old', 'Due New'], $this->names($groups['due']));
        $this->assertSame(150.0, $groups['due']['total']);
        $this->assertSame(['Hold Soon', 'Hold Late'], $this->names($groups['coming']));
        $this->assertSame(70.0, $groups['coming']['total']);
        $this->assertSame(['Still Out'], $this->names($groups['out']));
        $this->assertSame(4, $groups['coming']['rows'][0]['days']);
        // Not back yet: 3 days to the return, then the 14-day hold.
        $this->assertSame(17, $groups['out']['rows'][0]['days']);
    }

    public function test_settled_deposits_drafts_and_orders_without_a_deposit_are_left_out(): void
    {
        $this->order('Settled', 50, 20, ['deposit_status' => RentalOrder::DEPOSIT_REFUNDED]);
        $this->order('No Deposit', 0, 20);
        $this->order('Draft', 50, null, ['state' => RentalOrder::STATE_DRAFT]);
        $this->order('Cancelled', 50, null, ['state' => RentalOrder::STATE_CANCELLED]);

        $groups = $this->refundGroups(User::factory()->create(['is_super_admin' => true]));

        $this->assertSame([], $groups['due']['rows']);
        $this->assertSame([], $groups['coming']['rows']);
        $this->assertSame([], $groups['out']['rows']);
    }

    public function test_a_row_warns_about_unchecked_fines_and_return_charges(): void
    {
        $this->order('Clean', 50, 5, ['fines_checked_at' => Carbon::now()]);
        $this->order('Risky', 50, 4, ['has_damage' => true]);

        $rows = $this->refundGroups(User::factory()->create(['is_accountant' => true]))['coming']['rows'];

        $this->assertFalse($rows[0]['fines']);
        $this->assertFalse($rows[0]['charges']);
        $this->assertTrue($rows[1]['fines']);
        $this->assertTrue($rows[1]['charges']);
    }

    public function test_only_a_super_admin_is_offered_an_early_refund(): void
    {
        $this->order('Hold', 50, 3);

        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        Livewire::test(DepositRefunds::class)->assertSee('Refund early')->assertDontSee('Locked');

        $this->actingAs(User::factory()->create(['is_accountant' => true]));
        Livewire::test(DepositRefunds::class)
            ->assertDontSee('Refund early')
            ->assertSee('Locked')
            ->assertSee('Only a super-admin can refund a deposit before its hold ends.');
    }

    public function test_the_page_is_closed_to_anyone_who_cannot_settle_deposits(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Livewire::test(DepositRefunds::class)->assertForbidden();

        $this->actingAs(User::factory()->create());
        Livewire::test(DepositRefunds::class)->assertForbidden();
    }

    public function test_the_home_page_links_to_it_only_for_those_who_may_open_it(): void
    {
        $this->actingAs(User::factory()->create(['is_accountant' => true]));
        Livewire::test(RentalHome::class)->assertSeeHtml('data-refunds-link');

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Livewire::test(RentalHome::class)->assertDontSeeHtml('data-refunds-link');
    }
}
