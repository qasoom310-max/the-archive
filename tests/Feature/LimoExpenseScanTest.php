<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
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
        $answer = $read + ['readable' => true, 'date' => now()->toDateString(), 'payee' => 'Demo Station', 'category' => 'fuel', 'net_amount' => 0.0, 'vat_amount' => 0.0, 'total' => 0.0, 'description' => ''];

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
                    'description' => (string) $this->answer['description'],
                ];
            }
        });
    }

    /** @return array<string, mixed> */
    private function draft(array $read): array
    {
        return (new BillScanner())->draft($read + ['readable' => true, 'date' => now()->toDateString(), 'payee' => '', 'category' => 'fuel', 'net_amount' => 0.0, 'vat_amount' => 0.0, 'total' => 0.0, 'description' => '']);
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
            ->call('review', $token)
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

        Livewire::test(ExpenseScan::class)->call('review', $token)->call('discard')->assertSet('token', '');

        $this->assertSame([], Storage::disk(BillScanner::DISK)->allFiles());
    }

    public function test_one_persons_scan_cannot_be_used_by_another(): void
    {
        $this->readerReturning(['total' => 5.0]);
        $token = $this->post('/app/limousine/expense/scan', ['file' => UploadedFile::fake()->image('bill.jpg')])->json('token');

        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(ExpenseScan::class)->call('review', $token)->assertHasErrors('scan')->assertSet('token', '');
        Livewire::test(ExpenseScan::class)->call('review', 'not-a-token')->assertHasErrors('scan');
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
}
