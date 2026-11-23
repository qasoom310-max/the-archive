<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Limousine\Http\Controllers\ExpenseBillController;
use Modules\Limousine\Livewire\ExpenseScan;
use Modules\Limousine\Models\LimoExpense;
use Modules\Limousine\Services\BillScanner;
use Modules\Limousine\Support\BillReader;
use Modules\WhatsApp\Models\AssistantConfiguration;
use RuntimeException;
use Tests\TestCase;

/**
 * Scanning a bill into a limousine expense: the photo is read, a person
 * checks the figures, and only then is an expense saved with the photo kept.
 */
final class LimoExpenseScanTest extends TestCase
{
    use DatabaseMigrations;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($this->admin);
        app(ModuleManager::class)->install('limousine');
        app(ModuleManager::class)->install('whatsapp');
        Route::middleware('web')->group(base_path('Modules/Limousine/routes/web.php'));
        Route::getRoutes()->refreshNameLookups();
        AssistantConfiguration::query()->create(['ai_api_key' => 'sk-test', 'enabled' => false]);
        Storage::fake(BillScanner::DISK);
    }

    /**
     * @param array<string, mixed> $read
     */
    private function readerReturning(array $read): void
    {
        $answer = $read + ['readable' => true, 'date' => now()->toDateString(), 'payee' => 'Demo Station', 'category' => 'fuel', 'net_amount' => 0.0, 'vat_amount' => 0.0, 'total' => 0.0, 'currency' => 'BHD', 'description' => ''];

        app()->instance(BillReader::class, new class ($answer) implements BillReader {
            /** @param array<string, mixed> $answer */
            public function __construct(private array $answer)
            {
            }

            public function read(string $apiKey, string $model, string $image, string $mime): array
            {
                if (($this->answer['fail'] ?? false) === true) {
                    throw new RuntimeException('down');
                }

                return [
                    'readable' => (bool) $this->answer['readable'],
                    'date' => (string) $this->answer['date'],
                    'payee' => (string) $this->answer['payee'],
                    'category' => (string) $this->answer['category'],
                    'net_amount' => (float) $this->answer['net_amount'],
                    'vat_amount' => (float) $this->answer['vat_amount'],
                    'total' => (float) $this->answer['total'],
                    'currency' => (string) $this->answer['currency'],
                    'description' => (string) $this->answer['description'],
                ];
            }
        });
    }

    /** @return array<string, mixed> */
    private function draft(array $read): array
    {
        return (new BillScanner())->draft($read + ['readable' => true, 'date' => now()->toDateString(), 'payee' => '', 'category' => 'fuel', 'net_amount' => 0.0, 'vat_amount' => 0.0, 'total' => 0.0, 'currency' => 'BHD', 'description' => '']);
    }

    public function test_the_vat_rate_is_recognised_from_the_figures_on_the_bill(): void
    {
        // Bahrain's 10%, all three figures printed.
        $d = $this->draft(['net_amount' => 20.0, 'vat_amount' => 2.0, 'total' => 22.0]);
        $this->assertSame([20.0, 10, 2.0, 22.0], [$d['net_amount'], $d['vat_rate'], $d['vat_amount'], $d['total']]);

        // Saudi 15%, only the VAT and the total printed.
        $d = $this->draft(['vat_amount' => 15.0, 'total' => 115.0]);
        $this->assertSame([100.0, 15, 115.0], [$d['net_amount'], $d['vat_rate'], $d['total']]);

        // Only a total: nothing says there was VAT, so none is assumed.
        $d = $this->draft(['total' => 7.5]);
        $this->assertSame([7.5, 0, 7.5, []], [$d['net_amount'], $d['vat_rate'], $d['total'], $d['check']]);
    }

    /** The money paid must stay right even when the rate is one we do not offer. */
    public function test_a_rate_we_do_not_offer_enters_the_full_amount_and_says_so(): void
    {
        $d = $this->draft(['net_amount' => 100.0, 'vat_amount' => 5.0, 'total' => 105.0]);

        $this->assertSame([105.0, 0, 105.0], [$d['net_amount'], $d['vat_rate'], $d['total']]);
        $this->assertCount(1, $d['check']);
    }

    public function test_a_date_that_cannot_be_right_falls_back_to_today_with_a_warning(): void
    {
        foreach (['', '2019-01-01', '2026-02-31', 'yesterday'] as $bad) {
            $d = $this->draft(['date' => $bad, 'total' => 5.0]);
            $this->assertSame(now()->toDateString(), $d['date'], $bad);
            $this->assertNotSame([], $d['check'], $bad);
        }

        $this->assertSame('other', $this->draft(['category' => 'yachts', 'total' => 5.0])['category']);
    }

    public function test_a_scanned_bill_becomes_an_expense_only_after_it_is_checked_and_saved(): void
    {
        $this->readerReturning(['net_amount' => 20.0, 'vat_amount' => 2.0, 'total' => 22.0, 'description' => 'Petrol']);

        $token = $this->post('/app/limousine/expense/scan', ['file' => UploadedFile::fake()->image('bill.jpg', 600, 800)])
            ->assertOk()->json('token');

        $this->assertSame(0, LimoExpense::query()->count());

        $page = Livewire::test(ExpenseScan::class)
            ->set('driver', 'none')->call('review', $token)
            ->assertSet('net_amount', '20')
            ->assertSet('vat_rate', '10')
            ->assertSet('payee', 'Demo Station')
            // The person corrects the reading before saving.
            ->set('net_amount', '30')
            ->set('vat_rate', '15')
            ->call('save')
            ->assertHasNoErrors();

        $expense = LimoExpense::query()->sole();
        $page->assertRedirect(url('/app/limousine/expense/' . $expense->id));

        $this->assertSame([30.0, 15, 4.5, 34.5, 'fuel', 'Demo Station', 'Petrol'], [$expense->net_amount, $expense->vat_rate, $expense->vat_amount, $expense->amount, $expense->category, $expense->payee, $expense->notes]);
        $this->assertNotNull($expense->bill_path);
        Storage::disk(BillScanner::DISK)->assertExists((string) $expense->bill_path);

        // The photo opens from the expense; the scan cannot be saved twice.
        $this->get('/app/limousine/expense/' . $expense->id . '/bill')->assertOk();
        $this->get('/app/limousine/expense/' . $expense->id)->assertOk()->assertSee('View the scanned bill');
        $this->assertNull(Cache::get(ExpenseBillController::cacheKey($token)));
    }

    /** A Saudi bill: typed and shown in riyals, saved in BHD at the rate on screen. */
    public function test_a_bill_in_another_currency_is_converted_to_bhd(): void
    {
        Http::fake(['open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['BHD' => 0.1]])]);
        $this->readerReturning(['net_amount' => 200.0, 'vat_amount' => 30.0, 'total' => 230.0, 'currency' => 'SAR']);

        $token = $this->post('/app/limousine/expense/scan', ['file' => UploadedFile::fake()->image('bill.jpg')])->json('token');

        Livewire::test(ExpenseScan::class)
            ->set('driver', 'none')->call('review', $token)
            ->assertSet('currency', 'SAR')
            ->assertSet('net_amount', '200')
            ->assertSet('vat_rate', '15')
            ->assertSet('exchange_rate', '0.1')
            ->assertSee('This bill is in SAR')
            ->assertViewHas('bhdTotal', 23.0)
            ->call('save')
            ->assertHasNoErrors();

        $expense = LimoExpense::query()->sole();
        $this->assertSame([20.0, 15, 3.0, 23.0], [$expense->net_amount, $expense->vat_rate, $expense->vat_amount, $expense->amount]);
        $this->assertSame(['SAR', 200.0, 0.1], [$expense->bill_currency, $expense->bill_amount, $expense->bill_rate]);

        $this->get('/app/limousine/expense/' . $expense->id)->assertOk()->assertSee('1 SAR = 0.1 BHD');
    }

    public function test_the_currency_and_the_rate_can_be_corrected_before_saving(): void
    {
        // The rate lookup is down: the standard rate is used instead of blocking.
        Http::fake(['open.er-api.com/*' => Http::response([], 500)]);
        $this->readerReturning(['total' => 50.0, 'currency' => 'UNKNOWN']);

        $token = $this->post('/app/limousine/expense/scan', ['file' => UploadedFile::fake()->image('bill.jpg')])->json('token');

        $page = Livewire::test(ExpenseScan::class)
            ->set('driver', 'none')->call('review', $token)
            // Not readable from the bill: BHD, no conversion, and a note saying so.
            ->assertSet('currency', 'BHD')
            ->assertSet('exchange_rate', '1')
            ->assertSee('The currency could not be read')
            ->set('currency', 'AED')
            ->assertSet('exchange_rate', '0.10238')
            ->set('exchange_rate', '0')
            ->call('save')
            ->assertHasErrors('exchange_rate');

        $this->assertSame(0, LimoExpense::query()->count());

        $page->set('exchange_rate', '0.1')->call('save')->assertHasNoErrors();

        $expense = LimoExpense::query()->sole();
        $this->assertSame([5.0, 5.0, 'AED', 50.0, 0.1], [$expense->net_amount, $expense->amount, $expense->bill_currency, $expense->bill_amount, $expense->bill_rate]);
    }

    /** A BHD bill is saved as typed: no lookup, nothing remembered about a currency. */
    public function test_a_bhd_bill_is_not_converted(): void
    {
        Http::fake();
        $this->readerReturning(['total' => 12.5]);

        $token = $this->post('/app/limousine/expense/scan', ['file' => UploadedFile::fake()->image('bill.jpg')])->json('token');

        Livewire::test(ExpenseScan::class)
            ->set('driver', 'none')->call('review', $token)
            // A stale rate left in the box must not touch a BHD amount.
            ->set('exchange_rate', '7')
            ->call('save')
            ->assertHasNoErrors();

        Http::assertNothingSent();
        $expense = LimoExpense::query()->sole();
        $this->assertSame([12.5, 'BHD', null, null], [$expense->refresh()->amount, $expense->bill_currency, $expense->bill_amount, $expense->bill_rate]);
    }

    public function test_a_photo_that_is_not_a_bill_or_a_failed_reading_saves_nothing(): void
    {
        $this->readerReturning(['readable' => false]);
        $this->postJson('/app/limousine/expense/scan', ['file' => UploadedFile::fake()->image('cat.jpg')])->assertStatus(422);

        $this->readerReturning(['fail' => true]);
        $this->postJson('/app/limousine/expense/scan', ['file' => UploadedFile::fake()->image('bill.jpg')])->assertStatus(502);

        $this->postJson('/app/limousine/expense/scan', ['file' => UploadedFile::fake()->create('bill.pdf', 20, 'application/pdf')])->assertStatus(422);

        $this->assertSame([], Storage::disk(BillScanner::DISK)->allFiles());
        $this->assertSame(0, LimoExpense::query()->count());
    }

    public function test_scan_another_throws_the_photo_away(): void
    {
        $this->readerReturning(['total' => 5.0]);
        $token = $this->post('/app/limousine/expense/scan', ['file' => UploadedFile::fake()->image('bill.jpg')])->json('token');
        $this->assertCount(1, Storage::disk(BillScanner::DISK)->allFiles());

        Livewire::test(ExpenseScan::class)->set('driver', 'none')->call('review', $token)->call('discard')->assertSet('token', '');

        $this->assertSame([], Storage::disk(BillScanner::DISK)->allFiles());
    }

    public function test_one_persons_scan_cannot_be_used_by_another(): void
    {
        $this->readerReturning(['total' => 5.0]);
        $token = $this->post('/app/limousine/expense/scan', ['file' => UploadedFile::fake()->image('bill.jpg')])->json('token');

        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(ExpenseScan::class)->set('driver', 'none')->call('review', $token)->assertHasErrors('scan')->assertSet('token', '');
        Livewire::test(ExpenseScan::class)->set('driver', 'none')->call('review', 'not-a-token')->assertHasErrors('scan');
    }

    public function test_only_people_who_may_add_expenses_can_scan(): void
    {
        $this->readerReturning(['total' => 5.0]);
        $expense = LimoExpense::query()->create(['date' => now()->toDateString(), 'net_amount' => 5]);

        $this->actingAs(User::factory()->create());

        Livewire::test(ExpenseScan::class)->assertForbidden();
        $this->postJson('/app/limousine/expense/scan', ['file' => UploadedFile::fake()->image('bill.jpg')])->assertForbidden();
        $this->get('/app/limousine/expense/' . $expense->id . '/bill')->assertForbidden();
    }

    public function test_without_a_claude_key_the_page_says_so_and_nothing_is_uploaded(): void
    {
        AssistantConfiguration::query()->delete();

        Livewire::test(ExpenseScan::class)->assertSee('Bill scanning is not set up yet.');
        $this->postJson('/app/limousine/expense/scan', ['file' => UploadedFile::fake()->image('bill.jpg')])->assertStatus(409);
        $this->assertSame([], Storage::disk(BillScanner::DISK)->allFiles());
    }

    /** A pile of bills on a phone: save one, land back on the camera, save the next. */
    public function test_save_and_scan_next_saves_and_returns_to_the_camera(): void
    {
        $this->readerReturning(['net_amount' => 20.0, 'vat_amount' => 2.0, 'total' => 22.0]);
        $upload = fn (): string => (string) $this->post('/app/limousine/expense/scan', ['file' => UploadedFile::fake()->image('bill.jpg', 600, 800)])
            ->assertOk()->json('token');
        $first = $upload();

        $page = Livewire::test(ExpenseScan::class)
            ->set('driver', 'none')->call('review', $first)
            ->assertSee('RF/L/EXP123')            // the number it will get, before saving
            ->call('saveAndNext')
            ->assertHasNoErrors()
            ->assertNoRedirect()
            ->assertSet('token', '')
            ->assertSee('Saved as RF/L/EXP123')
            ->assertSee('Take a photo');

        $this->assertSame(1, LimoExpense::query()->count());
        // The photo stays with the expense; "next" must not throw it away.
        Storage::disk(BillScanner::DISK)->assertExists((string) LimoExpense::query()->firstOrFail()->bill_path);

        $page->set('driver', 'none')->call('review', $upload())->call('saveAndNext')->assertSee('Saved as RF/L/EXP124');
        $this->assertSame(2, LimoExpense::query()->count());
    }

    /** One driver's pile: choose the driver once, every bill is saved under them. */
    public function test_a_run_of_bills_is_saved_under_the_chosen_driver(): void
    {
        $this->readerReturning(['net_amount' => 20.0, 'vat_amount' => 2.0, 'total' => 22.0]);
        $upload = fn (): string => (string) $this->post('/app/limousine/expense/scan', ['file' => UploadedFile::fake()->image('bill.jpg', 600, 800)])
            ->assertOk()->json('token');
        $ali = \Modules\Limousine\Models\LimoDriver::query()->create(['name' => 'Ali Driver']);
        $sami = \Modules\Limousine\Models\LimoDriver::query()->create(['name' => 'Sami Driver']);

        $page = Livewire::test(ExpenseScan::class)
            // Nothing chosen yet: the question, not the camera.
            ->assertSee('Whose bills are these?')
            ->assertDontSeeHtml('capture="environment"')
            ->set('driver', (string) $ali->id)
            ->assertSeeHtml('capture="environment"')
            ->assertSee('Ali Driver')
            ->call('review', $upload())->call('saveAndNext')
            ->call('review', $upload())->call('saveAndNext')
            ->assertSet('driver', (string) $ali->id)
            ->assertSet('savedCount', 2)
            ->assertSet('savedTotal', 44.0);

        $this->assertSame([$ali->id, $ali->id], LimoExpense::query()->orderBy('id')->pluck('driver_id')->all());

        // Finished with Ali: the next driver starts a new count.
        $page->call('changeDriver')
            ->assertSet('driver', '')
            ->assertSee('Whose bills are these?')
            ->set('driver', (string) $sami->id)
            ->assertSet('savedCount', 0)
            ->call('review', $upload())->call('saveAndNext');

        $this->assertSame($sami->id, LimoExpense::query()->latest('id')->value('driver_id'));
        $this->assertSame('Sami Driver', LimoExpense::query()->latest('id')->firstOrFail()->driver_name);
    }

    public function test_a_bill_is_not_saved_until_it_is_said_whose_it_is(): void
    {
        $this->readerReturning(['net_amount' => 20.0, 'vat_amount' => 2.0, 'total' => 22.0]);
        $token = (string) $this->post('/app/limousine/expense/scan', ['file' => UploadedFile::fake()->image('bill.jpg', 600, 800)])->json('token');

        $page = Livewire::test(ExpenseScan::class)->call('review', $token);

        // Nothing chosen, and a driver that does not exist, are both refused.
        $page->call('saveAndNext')->assertHasErrors('driver');
        $page->set('driver', '99999')->call('saveAndNext')->assertHasErrors('driver');
        $this->assertSame(0, LimoExpense::query()->count());

        // An office bill is a real answer: saved with no driver.
        $page->set('driver', ExpenseScan::NO_DRIVER)->call('saveAndNext')->assertHasNoErrors();
        $this->assertNull(LimoExpense::query()->firstOrFail()->driver_id);
    }

    /** Drivers are many: the list offers them as one dropdown, never a button each. */
    public function test_the_expenses_list_filters_by_driver_from_one_dropdown(): void
    {
        $ali = \Modules\Limousine\Models\LimoDriver::query()->create(['name' => 'Ali Driver']);
        \Modules\Limousine\Models\LimoDriver::query()->create(['name' => 'Sami Driver']);
        LimoExpense::query()->create(['date' => now(), 'net_amount' => 5, 'payee' => 'Ali Fuel Stop', 'driver_id' => $ali->id]);
        LimoExpense::query()->create(['date' => now(), 'net_amount' => 7, 'payee' => 'Office Paper Shop']);

        \Livewire\Livewire::test(\App\Livewire\Views\ListView::class, ['model' => LimoExpense::class, 'modelKey' => 'limousine.expense'])
            ->assertSeeHtml('data-dynamic-select="driver"')
            ->assertDontSeeHtml("applyDynamicFilter('driver', '" . $ali->id . "')")
            ->call('applyDynamicFilter', 'driver', (string) $ali->id)
            ->assertSee('Ali Fuel Stop')
            ->assertDontSee('Office Paper Shop')
            ->call('applyDynamicFilter', 'driver', '')
            ->assertSee('Office Paper Shop');
    }

    /** @return array{0: \Modules\Limousine\Models\LimoDriver, 1: \Modules\Limousine\Models\LimoPettyAdvance} */
    private function driverWithCash(string $name = 'Sam Driver', float $cash = 50): array
    {
        $driver = \Modules\Limousine\Models\LimoDriver::query()->create(['name' => $name]);
        $petty = app(\Modules\Limousine\Services\PettyCash::class);
        $petty->topUp(500, now()->toDateString(), null, 'Owner');
        $advance = $petty->issue($driver, $cash, now()->toDateString(), null, 'Abbas');
        $this->assertNotNull($advance);
        \Modules\Limousine\Models\LimoPettyCategory::query()->firstOrCreate(['name' => 'Fuel'], ['expense_category' => 'fuel']);

        return [$driver, $advance];
    }

    private function parkedBill(): string
    {
        $this->readerReturning(['net_amount' => 10.0, 'vat_amount' => 1.0, 'total' => 11.0, 'category' => 'fuel']);

        return (string) $this->post('/app/limousine/expense/scan', ['file' => UploadedFile::fake()->image('bill.jpg', 600, 800)])->assertOk()->json('token');
    }

    /** The owner: a receipt scanned for a driver comes off the cash THAT driver was given. */
    public function test_a_scanned_bill_counts_against_the_cash_its_driver_was_given(): void
    {
        [$driver, $advance] = $this->driverWithCash();

        $page = Livewire::test(ExpenseScan::class)
            ->set('driver', (string) $driver->id)
            // One open advance: chosen for them.
            ->assertSet('advance', (string) $advance->id)
            ->call('review', $this->parkedBill())
            ->assertSeeHtml('data-scan-advance')
            ->call('saveAndNext')
            ->assertHasNoErrors();

        $expense = LimoExpense::query()->firstOrFail();
        $line = $advance->lines()->firstOrFail();
        $this->assertSame($expense->id, $line->expense_id);
        $this->assertSame(11.0, $line->amount);
        $this->assertSame($driver->id, $expense->driver_id);
        $this->assertSame(11.0, $advance->fresh()?->linesTotal());

        // The run keeps the answer for the next bill.
        $page->call('review', $this->parkedBill())->assertSet('advance', (string) $advance->id);

        // Settling does not write the scanned receipt a second time.
        $petty = app(\Modules\Limousine\Services\PettyCash::class);
        $petty->confirm($advance, 'Accountant');
        $result = $petty->settle($advance->fresh() ?? $advance, 'Accountant');
        $this->assertSame(39.0, $result['shortfall'] ?? null);
        $this->assertSame(1, LimoExpense::query()->count());
    }

    public function test_the_drivers_oldest_cash_is_chosen_and_can_be_changed(): void
    {
        [$driver, $first] = $this->driverWithCash();
        $this->driverWithCash('Other Driver');
        // A second open advance of the same driver: the oldest is still the default.
        $second = app(\Modules\Limousine\Services\PettyCash::class)->issue($driver, 20, now()->toDateString(), null, 'Abbas');
        $this->assertNotNull($second);

        $page = Livewire::test(ExpenseScan::class)
            ->set('driver', (string) $driver->id)
            ->assertSet('advance', (string) $first->id)
            ->call('review', $this->parkedBill());

        // Another driver's cash is refused.
        $other = \Modules\Limousine\Models\LimoPettyAdvance::query()->where('driver_id', '!=', $driver->id)->firstOrFail();
        $page->set('advance', (string) $other->id)->call('saveAndNext')->assertHasErrors('advance');
        $this->assertSame(0, LimoExpense::query()->count());

        // "Not from petty cash" saves the expense and touches no cash at all.
        $page->set('advance', ExpenseScan::NO_ADVANCE)->call('saveAndNext')->assertHasNoErrors();
        $this->assertSame(1, LimoExpense::query()->count());
        $this->assertSame(0, \Modules\Limousine\Models\LimoPettyLine::query()->count());
    }

    public function test_a_settled_advance_is_not_offered_and_corrections_follow_the_receipt(): void
    {
        [$driver, $advance] = $this->driverWithCash();

        Livewire::test(ExpenseScan::class)->set('driver', (string) $driver->id)
            ->call('review', $this->parkedBill())->call('saveAndNext')->assertHasNoErrors();

        // The expense amount is corrected: the receipt on the cash follows.
        $expense = LimoExpense::query()->firstOrFail();
        $expense->forceFill(['net_amount' => 20, 'vat_rate' => 0])->save();
        $this->assertSame(20.0, $advance->lines()->firstOrFail()->amount);

        // Settled: no longer offered.
        $petty = app(\Modules\Limousine\Services\PettyCash::class);
        $petty->confirm($advance, 'Accountant');
        $petty->settle($advance->fresh() ?? $advance, 'Accountant');

        Livewire::test(ExpenseScan::class)->set('driver', (string) $driver->id)
            ->call('review', $this->parkedBill())
            ->assertDontSeeHtml('data-scan-advance')
            ->call('saveAndNext')->assertHasNoErrors();
    }
}
