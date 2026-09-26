<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Backup\DatabaseBackup;
use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalInvoice;
use Modules\Rental\Models\RentalReceipt;
use Tests\TestCase;

/**
 * Limousine receipts ("L-RCPT…") that the historical import put into the Rent
 * A Car receipts table are removed; rental receipts ("RCP…") stay.
 */
final class RentalRemoveLimoReceiptsTest extends TestCase
{
    use DatabaseMigrations;

    public function test_limousine_receipts_leave_the_rental_list_and_rental_ones_stay(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');

        $customer = RentalCustomer::query()->create(['name' => 'Go Easy Car Rental']);
        $invoice = RentalInvoice::query()->create(['customer_id' => $customer->id, 'issue_date' => now(), 'total' => 100]);

        $rental = RentalReceipt::query()->create(['reference' => 'RCP00010', 'invoice_id' => $invoice->id, 'customer_id' => $customer->id, 'date' => now(), 'amount' => 40, 'method' => 'cash']);
        RentalReceipt::query()->create(['reference' => 'L-RCPT12968', 'invoice_id' => $invoice->id, 'customer_id' => $customer->id, 'date' => now(), 'amount' => 60, 'method' => 'cash']);
        RentalReceipt::query()->create(['reference' => 'L-RCPT12969', 'customer_id' => $customer->id, 'date' => now(), 'amount' => 15, 'method' => 'cash']);

        $this->assertSame(RentalInvoice::STATUS_PAID, $invoice->fresh()?->status);

        $migration = require base_path('Modules/Rental/database/migrations/2026_09_26_900044_remove_limousine_receipts_from_rental_receipts.php');
        $migration->up();

        $this->assertSame(['RCP00010'], RentalReceipt::query()->pluck('reference')->all());
        $this->assertTrue(RentalReceipt::query()->whereKey($rental->id)->exists());

        // The invoice is re-totalled from its real rental receipt only.
        $fresh = $invoice->fresh();
        $this->assertNotNull($fresh);
        $this->assertEqualsWithDelta(40.0, (float) $fresh->amount_paid, 0.001);
        $this->assertSame(RentalInvoice::STATUS_PARTIAL, $fresh->status);

        // A backup was taken before anything was deleted.
        $this->assertNotEmpty(app(DatabaseBackup::class)->list());

        // Running again changes nothing.
        $migration->up();
        $this->assertSame(1, RentalReceipt::query()->count());
    }
}
