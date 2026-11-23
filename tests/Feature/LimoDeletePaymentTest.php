<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\ReceiptForm;
use Modules\Limousine\Livewire\Receipts;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoReceipt;
use Modules\Limousine\Services\BookingPayments;
use Tests\TestCase;

/**
 * A payment entered by mistake (a whole bulk payment, or one receipt) can be
 * deleted by the owner / Accountant: the receipts go, the trips' paid amount
 * and the invoices go back, and the activity log keeps what was removed.
 */
final class LimoDeletePaymentTest extends TestCase
{
    use DatabaseMigrations;

    private LimoCustomer $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => true]));
        app(ModuleManager::class)->install('limousine');
        $this->grantEveryone('limousine.receipt');
        $this->company = LimoCustomer::query()->create(['name' => 'Demo Co', 'type' => 'company']);
    }

    private function trip(float $fare): LimoBooking
    {
        $booking = LimoBooking::query()->create(['customer_id' => $this->company->id, 'fare' => $fare]);
        $booking->syncInvoice();

        return $booking->refresh();
    }

    public function test_a_whole_bulk_payment_is_taken_back(): void
    {
        $a = $this->trip(100);
        $b = $this->trip(50);
        $other = $this->trip(30);
        $pay = app(BookingPayments::class);
        $pay->receive($a, 100, 'transfer', null, 'BATCH-1');
        $pay->receive($b, 20, 'transfer', null, 'BATCH-1');
        $pay->receive($other, 30, 'cash');

        Livewire::test(Receipts::class)->call('deletePayment', 'BATCH-1');

        $this->assertSame(1, LimoReceipt::query()->count());
        $this->assertSame(0.0, (float) $a->refresh()->advance);
        $this->assertSame(0.0, (float) $b->refresh()->advance);
        $this->assertNotSame(LimoBooking::PAYMENT_PAID, $a->payment_status);
        $inv = LimoInvoice::query()->where('booking_id', $a->id)->firstOrFail();
        $this->assertSame(LimoInvoice::STATUS_UNPAID, $inv->status);
        $this->assertSame(0.0, (float) $inv->amount_paid);
        // The untouched payment stays paid.
        $this->assertSame(30.0, (float) $other->refresh()->advance);
        $this->assertDatabaseHas('activity_logs', ['action' => 'deleted']);
    }

    public function test_from_the_receipt_page_a_bulk_slice_deletes_its_whole_bulk_payment(): void
    {
        $a = $this->trip(100);
        $b = $this->trip(50);
        app(BookingPayments::class)->receive($a, 40, 'transfer', null, 'BATCH-2');
        app(BookingPayments::class)->receive($b, 10, 'transfer', null, 'BATCH-2');

        $id = (int) LimoReceipt::query()->min('id');
        Livewire::test(ReceiptForm::class, ['id' => $id])
            ->assertSeeHtml('data-delete-payment')
            ->call('deletePayment');

        $this->assertSame(0, LimoReceipt::query()->count());
        $this->assertSame(0.0, (float) $b->refresh()->advance);
    }

    public function test_only_the_owner_or_accountant_may_delete(): void
    {
        $a = $this->trip(100);
        app(BookingPayments::class)->receive($a, 40, 'cash');
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(Receipts::class)->call('deletePayment', 'r-' . LimoReceipt::query()->value('id'))->assertForbidden();
        $this->assertSame(1, LimoReceipt::query()->count());
    }
}
