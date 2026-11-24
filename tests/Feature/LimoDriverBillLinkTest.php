<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Limousine\Livewire\DriverBills;
use Modules\Limousine\Livewire\ExpenseScan;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoDriverBill;
use Modules\Limousine\Models\LimoExpense;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Services\BillScanner;
use Modules\Limousine\Services\LimoQueueRows;
use Modules\Limousine\Services\PettyCash;
use Modules\Limousine\Support\BillReader;
use Modules\Limousine\Support\DriverBillLink;
use Modules\WhatsApp\Models\AssistantConfiguration;
use Tests\TestCase;

/**
 * The driver's own bill link: sent with the copied trip, good for 48 hours and
 * only for the driver on the trip; his photos wait for the office, which checks
 * each one and saves it (with the petty-cash question) or sets it aside.
 */
final class LimoDriverBillLinkTest extends TestCase
{
    use DatabaseMigrations;

    private LimoDriver $driver;

    private LimoLeg $leg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
        app(ModuleManager::class)->install('whatsapp');
        Route::middleware('web')->group(base_path('Modules/Limousine/routes/web.php'));
        Route::getRoutes()->refreshNameLookups();
        AssistantConfiguration::query()->create(['ai_api_key' => 'sk-test', 'enabled' => false]);
        Storage::fake(BillScanner::DISK);

        app()->instance(BillReader::class, new class implements BillReader {
            public function read(string $apiKey, string $model, string $image, string $mime): array
            {
                return ['readable' => true, 'date' => now()->toDateString(), 'payee' => 'Demo Station', 'category' => 'fuel',
                    'net_amount' => 10.0, 'vat_amount' => 1.0, 'total' => 11.0, 'currency' => 'BHD', 'description' => 'Petrol'];
            }
        });

        $this->driver = LimoDriver::query()->create(['name' => 'Sam Driver']);
        $booking = LimoBooking::query()->create(['customer_id' => LimoCustomer::query()->create(['name' => 'Demo Co'])->id, 'fare' => 30]);
        $this->leg = $booking->legs()->create([
            'sequence' => 0, 'service_type' => 'transfer', 'status' => LimoLeg::STATUS_CONFIRMED,
            'from_location' => 'A', 'to_location' => 'B', 'start_at' => now()->addHours(3), 'days' => 1,
            'rate' => 30, 'rate_basis' => 'trip', 'net_amount' => 30, 'driver_id' => $this->driver->id, 'car_id' => 1,
        ]);
    }

    private function link(): string
    {
        return (string) DriverBillLink::for($this->leg);
    }

    public function test_the_copied_message_carries_a_separate_bills_link(): void
    {
        // The copied message is now the trip link; the bill link and its
        // warning sit on the trip page.
        $text = app(LimoQueueRows::class)->whatsappText($this->leg);
        $this->assertStringContainsString('/d/', $text);
        $this->assertStringContainsString('/b/', $text);
        $this->assertStringContainsString(DriverBillLink::NOTE_EN, $text);
        $this->assertNotNull(DriverBillLink::for($this->leg));

        $this->leg->forceFill(['driver_id' => null])->save();
        $this->assertNull(DriverBillLink::for($this->leg));
    }

    public function test_the_driver_sends_bills_that_wait_for_the_office(): void
    {
        $link = $this->link();
        Auth::logout();

        $this->get($link)->assertOk()->assertSee('Send your bills')->assertSee((string) $this->leg->reference)
            ->assertSee(DriverBillLink::NOTE_EN)->assertSee(DriverBillLink::NOTE_AR);
        $this->post($link, ['file' => UploadedFile::fake()->image('bill.jpg', 600, 800)])->assertOk()->assertJson(['sent' => 1]);

        $bill = LimoDriverBill::query()->firstOrFail();
        $this->assertSame($this->driver->id, $bill->driver_id);
        $this->assertSame($this->leg->id, $bill->leg_id);
        $this->assertSame(LimoDriverBill::STATUS_NEW, $bill->status);
        $this->assertSame(11.0, $bill->readTotal());
        Storage::disk(BillScanner::DISK)->assertExists($bill->photo_path);
        // Nothing is an expense until the office saves it.
        $this->assertSame(0, LimoExpense::query()->count());
    }

    public function test_the_link_stops_after_48_hours_or_when_the_trip_changes_driver(): void
    {
        $link = $this->link();
        Auth::logout();

        Carbon::setTestNow(now()->addHours(49));
        $this->get($link)->assertStatus(410)->assertSee('This link has expired');
        $this->post($link, ['file' => UploadedFile::fake()->image('bill.jpg')])->assertStatus(410);
        Carbon::setTestNow();

        $this->leg->forceFill(['driver_id' => LimoDriver::query()->create(['name' => 'Other'])->id])->save();
        $this->get($link)->assertStatus(410);

        // An edited address is refused too.
        $this->get(str_replace('/' . $this->driver->id . '?', '/999?', $link))->assertStatus(410);
        $this->assertSame(0, LimoDriverBill::query()->count());
    }

    public function test_a_link_takes_a_limited_number_of_bills(): void
    {
        $link = $this->link();
        for ($i = 0; $i < LimoDriverBill::MAX_PER_LINK; $i++) {
            LimoDriverBill::query()->create(['driver_id' => $this->driver->id, 'leg_id' => $this->leg->id, 'photo_path' => 'x.jpg']);
        }

        $this->post($link, ['file' => UploadedFile::fake()->image('bill.jpg')])->assertStatus(422);
    }

    public function test_the_office_checks_a_bill_and_saves_it_against_the_drivers_cash(): void
    {
        $petty = app(PettyCash::class);
        $petty->topUp(100, now()->toDateString(), null, 'Owner');
        $advance = $petty->issue($this->driver, 40, now()->toDateString(), null, 'Abbas');
        $this->assertNotNull($advance);
        $bill = LimoDriverBill::query()->create(['driver_id' => $this->driver->id, 'leg_id' => $this->leg->id, 'photo_path' => 'limo_expense_bills/drivers/b.jpg',
            'draft' => ['date' => now()->toDateString(), 'payee' => 'Demo Station', 'category' => 'fuel', 'currency' => 'BHD', 'net_amount' => 10, 'vat_rate' => 10, 'notes' => '', 'check' => []]]);

        Livewire::test(DriverBills::class)->assertSee('Sam Driver')->assertSeeHtml('?bill=' . $bill->id);

        Livewire::withQueryParams(['bill' => (string) $bill->id])->test(ExpenseScan::class)
            ->assertSet('billId', $bill->id)
            ->assertSet('driver', (string) $this->driver->id)
            ->assertSet('advance', (string) $advance->id)
            ->assertSet('net_amount', '10')
            ->assertSeeHtml('data-driver-bill-photo')
            // Not without the original paper: the VAT authority asks for it.
            ->call('save')
            ->assertHasErrors('originalReceived')
            ->set('originalReceived', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(url('/app/limousine/expense/driver-bills'));

        $expense = LimoExpense::query()->firstOrFail();
        $this->assertSame('limo_expense_bills/drivers/b.jpg', $expense->getAttribute('bill_path'));
        $this->assertSame($this->driver->id, $expense->driver_id);
        $this->assertNotNull($expense->original_received_at);
        $this->assertNotSame('', (string) $expense->original_received_by);
        $this->assertSame(LimoDriverBill::STATUS_SAVED, $bill->fresh()?->status);
        $this->assertSame($expense->id, $bill->fresh()?->expense_id);
        $this->assertSame(11.0, $advance->fresh()?->linesTotal());

        // Checked once only.
        Livewire::withQueryParams(['bill' => (string) $bill->id])->test(ExpenseScan::class)->assertHasErrors('scan');
    }

    public function test_a_rejected_bill_waits_under_the_drivers_name_until_he_is_told(): void
    {
        $bill = LimoDriverBill::query()->create(['driver_id' => $this->driver->id, 'leg_id' => $this->leg->id, 'photo_path' => 'p.jpg']);

        $page = Livewire::test(DriverBills::class)
            ->call('openReject', $bill->id)
            ->call('reject')
            ->assertHasErrors('rejectReason')
            ->set('rejectReason', 'Amount cannot be read')
            ->call('reject')
            ->assertHasNoErrors()
            ->assertSeeHtml('data-to-tell')
            ->assertSee('Sam Driver')
            ->assertSee('Amount cannot be read');

        $this->assertSame(LimoDriverBill::STATUS_REJECTED, $bill->fresh()?->status);
        $this->assertSame(0, LimoExpense::query()->count());

        // Told in person: recorded, and it leaves the "to tell" list.
        $page->call('markTold', $bill->id)->assertDontSeeHtml('data-to-tell');
        $this->assertNotNull($bill->fresh()?->told_at);
        $this->assertNotSame('', (string) $bill->fresh()?->told_by);

        // The driver's own page says nothing: he is told face to face.
        $link = $this->link();
        Auth::logout();
        $this->get($link)->assertOk()->assertDontSee('Amount cannot be read');
    }

    public function test_the_check_screen_rejects_with_a_reason_and_a_saved_bill_cannot_be_rejected(): void
    {
        $bill = LimoDriverBill::query()->create(['driver_id' => $this->driver->id, 'leg_id' => $this->leg->id, 'photo_path' => 'p.jpg']);

        Livewire::withQueryParams(['bill' => (string) $bill->id])->test(ExpenseScan::class)
            ->call('rejectBill')
            ->assertHasErrors('rejectReason')
            ->set('rejectReason', 'Not a company expense')
            ->call('rejectBill')
            ->assertRedirect(url('/app/limousine/expense/driver-bills'));
        $this->assertSame('Not a company expense', $bill->fresh()?->reject_reason);

        $saved = LimoDriverBill::query()->create(['driver_id' => $this->driver->id, 'leg_id' => $this->leg->id, 'photo_path' => 'q.jpg', 'status' => LimoDriverBill::STATUS_SAVED]);
        $this->assertFalse($saved->reject('late', null));
        $this->assertSame(LimoDriverBill::STATUS_SAVED, $saved->fresh()?->status);
    }

    public function test_the_photo_is_for_signed_in_staff_only(): void
    {
        Storage::disk(BillScanner::DISK)->put('limo_expense_bills/drivers/p.jpg', 'x');
        $bill = LimoDriverBill::query()->create(['driver_id' => $this->driver->id, 'leg_id' => $this->leg->id, 'photo_path' => 'limo_expense_bills/drivers/p.jpg']);

        $this->get('/app/limousine/expense/driver-bill/' . $bill->id . '/photo')->assertOk();

        Auth::logout();
        $this->get('/app/limousine/expense/driver-bill/' . $bill->id . '/photo')->assertRedirect();
    }
}
