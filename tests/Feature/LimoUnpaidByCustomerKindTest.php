<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Modules\Limousine\Livewire\Bookings;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Services\LimoQueueRows;
use Tests\TestCase;

/**
 * The Unpaid tab's two boxes: how many companies and how many individuals owe
 * money. Pressing one lists their names and narrows the trips under it.
 */
final class LimoUnpaidByCustomerKindTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
        Route::middleware('web')->group(base_path('Modules/Limousine/routes/web.php'));
        Route::getRoutes()->refreshNameLookups();
    }

    private function customer(string $name, string $type): LimoCustomer
    {
        return LimoCustomer::query()->create(['name' => $name, 'type' => $type]);
    }

    private function booking(?LimoCustomer $customer, float $fare, float $advance = 0.0, int $trips = 1): LimoBooking
    {
        $booking = LimoBooking::query()->create([
            'customer_id' => $customer?->id,
            'pickup_at' => now()->addDay(),
            'status' => LimoBooking::STATUS_QUEUE,
            'advance' => $advance,
            'payment_status' => LimoBooking::PAYMENT_UNPAID,
        ]);
        for ($i = 0; $i < $trips; $i++) {
            LimoLeg::query()->create([
                'legable_type' => LimoBooking::class, 'legable_id' => $booking->id, 'sequence' => $i,
                'status' => LimoLeg::STATUS_QUEUE, 'start_at' => now()->addDay(),
                'from_location' => 'Seef', 'to_location' => 'Bahrain Airport',
                'rate' => $fare / $trips, 'net_amount' => $fare / $trips,
            ]);
        }
        $paid = $advance + 0.0005 >= $fare ? LimoBooking::PAYMENT_PAID : LimoBooking::PAYMENT_UNPAID;
        LimoBooking::query()->whereKey($booking->id)->update(['fare' => $fare, 'amount' => $fare, 'payment_status' => $paid]);

        return $booking->refresh();
    }

    /** @return list<int> */
    private function bookingsListed(string $kind = '', int $customerId = 0): array
    {
        $ids = array_map(
            static fn (array $r): int => $r['booking_id'],
            app(LimoQueueRows::class)->all(LimoQueueRows::TAB_UNPAID, '', '', '', '', 'desc', false, '', $kind, $customerId),
        );
        sort($ids);

        return array_values(array_unique($ids));
    }

    public function test_the_boxes_count_customers_not_trips_and_add_up_what_each_owes(): void
    {
        $gulf = $this->customer('Gulf Hotels', LimoCustomer::TYPE_COMPANY);
        $ahmed = $this->customer('Ahmed Ali', 'individual');
        $sara = $this->customer('Sara Hasan', 'individual');

        $this->booking($gulf, 100, 0, 2);          // two trips on one booking
        $this->booking($gulf, 50, 20);             // part paid: 30 still owed
        $this->booking($ahmed, 40);
        $this->booking($sara, 70);
        $this->booking($sara, 25, 25);             // paid in full: not counted

        $kinds = app(LimoQueueRows::class)->unpaidCustomers();

        $this->assertCount(1, $kinds['company']);
        $this->assertSame('Gulf Hotels', $kinds['company'][0]['name']);
        $this->assertSame(3, $kinds['company'][0]['trips']);
        $this->assertEqualsWithDelta(130.0, $kinds['company'][0]['balance'], 0.001, 'a booking with two trips is owed once');

        $this->assertSame(['Sara Hasan', 'Ahmed Ali'], array_column($kinds['individual'], 'name'), 'whoever owes most comes first');
        $this->assertEqualsWithDelta(70.0, $kinds['individual'][0]['balance'], 0.001);
    }

    public function test_a_box_narrows_the_list_and_the_two_boxes_cover_every_trip(): void
    {
        $company = $this->booking($this->customer('Gulf Hotels', LimoCustomer::TYPE_COMPANY), 100);
        $person = $this->booking($this->customer('Ahmed Ali', 'individual'), 40);
        $nobody = $this->booking(null, 30);        // a booking with no customer on it

        $this->assertSame([$company->id], $this->bookingsListed('company'));
        $this->assertSame([$person->id, $nobody->id], $this->bookingsListed('individual'));
        $this->assertSame([$company->id, $person->id, $nobody->id], $this->bookingsListed());
        $this->assertSame([], $this->bookingsListed('anything-else'), 'an unknown box shows nothing, not everything');
    }

    public function test_pressing_a_box_then_a_name_on_the_screen(): void
    {
        $gulf = $this->customer('Gulf Hotels', LimoCustomer::TYPE_COMPANY);
        $delta = $this->customer('Delta Travel', LimoCustomer::TYPE_COMPANY);
        $ahmed = $this->customer('Ahmed Ali', 'individual');
        $this->booking($gulf, 100);
        $this->booking($delta, 60);
        $this->booking($ahmed, 40);

        $page = Livewire::test(Bookings::class)->call('openTab', 'unpaid');
        $page->assertSeeHtml('data-unpaid-kinds')->assertDontSeeHtml('data-unpaid-names');
        $this->assertSame(3, $page->viewData('legs')->total());

        $page->call('setCustomerKind', 'company')
            ->assertSeeHtml('data-unpaid-names')
            ->assertSee('Delta Travel')
            ->assertSet('customerKind', 'company');
        $this->assertSame(2, $page->viewData('legs')->total());

        $page->call('setUnpaidCustomer', $delta->id);
        $this->assertSame(1, $page->viewData('legs')->total());
        $this->assertSame($delta->id, $page->viewData('activeCustomer'));

        // The same name again shows the whole box; the same box again, everyone.
        $page->call('setUnpaidCustomer', $delta->id);
        $this->assertSame(2, $page->viewData('legs')->total());
        $page->call('setCustomerKind', 'company')->assertSet('customerKind', '');
        $this->assertSame(3, $page->viewData('legs')->total());
    }

    public function test_the_boxes_belong_to_the_unpaid_tab_only(): void
    {
        $this->booking($this->customer('Gulf Hotels', LimoCustomer::TYPE_COMPANY), 100);
        $this->booking($this->customer('Ahmed Ali', 'individual'), 40);

        $page = Livewire::test(Bookings::class)
            ->call('openTab', 'unpaid')
            ->call('setCustomerKind', 'company');
        $this->assertSame(1, $page->viewData('legs')->total());

        // Leaving the tab lets go of the box, so Queue is not quietly narrowed.
        $page->call('openTab', 'queue')->assertSet('customerKind', '')->assertDontSeeHtml('data-unpaid-kinds');
        $this->assertSame(2, $page->viewData('legs')->total());

        // A link that carries a box onto another tab is ignored there.
        $other = Livewire::withQueryParams(['tab' => 'queue', 'kind' => 'company'])->test(Bookings::class);
        $this->assertSame(2, $other->viewData('legs')->total());
    }

    public function test_a_name_that_left_the_list_falls_back_to_the_whole_box(): void
    {
        $gulf = $this->customer('Gulf Hotels', LimoCustomer::TYPE_COMPANY);
        $delta = $this->customer('Delta Travel', LimoCustomer::TYPE_COMPANY);
        $this->booking($gulf, 100);
        $paidLater = $this->booking($delta, 60);

        $page = Livewire::test(Bookings::class)
            ->call('openTab', 'unpaid')
            ->call('setCustomerKind', 'company')
            ->call('setUnpaidCustomer', $delta->id);
        $this->assertSame(1, $page->viewData('legs')->total());

        LimoBooking::query()->whereKey($paidLater->id)->update(['advance' => 60, 'payment_status' => LimoBooking::PAYMENT_PAID]);

        $page->call('$refresh');
        $this->assertSame(0, $page->viewData('activeCustomer'));
        $this->assertSame(1, $page->viewData('legs')->total(), 'the other company is still listed');
    }

    public function test_the_export_follows_the_box(): void
    {
        $this->booking($this->customer('Gulf Hotels', LimoCustomer::TYPE_COMPANY), 100);
        $this->booking($this->customer('Ahmed Ali', 'individual'), 40);

        $csv = $this->get('/app/limousine/booking/export/csv?tab=unpaid&kind=individual')->streamedContent();

        $this->assertStringContainsString('Ahmed Ali', $csv);
        $this->assertStringNotContainsString('Gulf Hotels', $csv);
    }
}
