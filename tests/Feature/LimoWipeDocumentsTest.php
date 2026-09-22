<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Erp\Tenancy\WorkspaceManager;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Limousine\Console\WipeDocuments;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoExpense;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoQuotation;
use Modules\Limousine\Models\LimoReceipt;
use Modules\Limousine\Services\BookingPayments;
use Tests\TestCase;

final class LimoWipeDocumentsTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');

        $kernel = $this->app?->make(Kernel::class);
        \assert($kernel instanceof ConsoleKernel);
        $kernel->registerCommand(new WipeDocuments());
    }

    private function seedDocuments(): LimoCustomer
    {
        $customer = LimoCustomer::query()->create(['name' => 'Travel Gate']);
        foreach ([LimoBooking::STATUS_QUEUE, LimoBooking::STATUS_COMPLETED, LimoBooking::STATUS_CANCELLED] as $status) {
            $booking = LimoBooking::query()->create([
                'customer_id' => $customer->id, 'fare' => 20, 'status' => $status,
            ]);
            $booking->legs()->create([
                'sequence' => 0, 'from_location' => 'A', 'to_location' => 'B',
                'start_at' => now(), 'rate' => 20, 'net_amount' => 20, 'status' => $status,
            ]);
            $booking->syncInvoice();
            app(BookingPayments::class)->receive($booking->fresh(), 10, 'cash');
        }

        $quotation = LimoQuotation::query()->create(['customer_id' => $customer->id]);
        $quotation->legs()->create(['sequence' => 0, 'from_location' => 'A', 'to_location' => 'B', 'rate' => 5]);

        LimoExpense::query()->create([
            'booking_id' => LimoBooking::query()->value('id'), 'description' => 'Fuel', 'amount' => 3,
            'date' => now()->toDateString(),
        ]);

        return $customer;
    }

    public function test_a_dry_run_counts_and_deletes_nothing(): void
    {
        $this->seedDocuments();
        $ws = app(WorkspaceManager::class)->ensureMain()->id;

        $this->artisan('limo:wipe-documents', ['--workspace' => $ws])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(3, LimoBooking::query()->count());
        $this->assertSame(1, LimoQuotation::query()->count());
    }

    public function test_force_deletes_every_document_but_keeps_customers_and_expenses(): void
    {
        $customer = $this->seedDocuments();
        $ws = app(WorkspaceManager::class)->ensureMain()->id;

        $this->artisan('limo:wipe-documents', ['--workspace' => $ws, '--force' => true])->assertSuccessful();

        $this->assertSame(0, LimoBooking::query()->count());
        $this->assertSame(0, LimoLeg::query()->count());
        $this->assertSame(0, LimoQuotation::query()->count());
        $this->assertSame(0, LimoInvoice::query()->count());
        $this->assertSame(0, LimoReceipt::query()->count());
        $this->assertTrue(LimoCustomer::query()->whereKey($customer->id)->exists());
        $this->assertNull(LimoExpense::query()->sole()->booking_id);
    }

    public function test_it_refuses_without_a_workspace_or_with_an_unknown_one(): void
    {
        $this->seedDocuments();

        $this->artisan('limo:wipe-documents', ['--force' => true])->assertFailed();
        $this->artisan('limo:wipe-documents', ['--workspace' => 999, '--force' => true])->assertFailed();

        $this->assertSame(3, LimoBooking::query()->count());
    }
}
