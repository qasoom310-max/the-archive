<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\ReceiptForm;
use Modules\Limousine\Livewire\Receipts;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoReceipt;
use Tests\TestCase;

/**
 * Writing a receipt by hand is the owner's job alone.
 *
 * Money taken on a booking issues its own receipt, so a hand-made one is a
 * correction rather than the normal way in — and two receipts for the same
 * payment is a hard mistake to spot after the fact.
 *
 * Editing an existing receipt is deliberately untouched: this guards creating
 * one from nothing, not correcting one that already exists.
 */
final class LimoManualReceiptTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('limousine');
    }

    private function asOwner(): User
    {
        $owner = User::factory()->create(['is_admin' => true, 'is_super_admin' => true]);
        $this->actingAs($owner);

        return $owner;
    }

    private function asAdmin(): User
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        return $admin;
    }

    private function invoice(): LimoInvoice
    {
        $customer = LimoCustomer::query()->create(['name' => 'Helen Friberg']);

        return LimoInvoice::query()->create([
            'customer_id' => $customer->id, 'issue_date' => now(), 'total' => 45,
        ]);
    }

    public function test_only_the_owner_is_offered_the_new_receipt_button(): void
    {
        $this->asOwner();
        Livewire::test(Receipts::class)->assertSee(__('New receipt'));

        $this->asAdmin();
        Livewire::test(Receipts::class)->assertDontSee(__('New receipt'));
    }

    public function test_a_regular_admin_cannot_open_the_new_receipt_form(): void
    {
        $this->asAdmin();

        // Hiding the button is cosmetic — the URL has to be closed too.
        Livewire::test(ReceiptForm::class)->assertForbidden();
    }

    public function test_the_owner_can_still_write_one_by_hand(): void
    {
        $this->asOwner();
        $invoice = $this->invoice();

        Livewire::test(ReceiptForm::class)
            ->set('invoice_id', $invoice->id)
            ->set('amount', 45)
            ->set('method', 'cash')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, LimoReceipt::query()->count());
    }

    public function test_save_re_checks_rather_than_trusting_the_open_page(): void
    {
        $this->asOwner();
        $invoice = $this->invoice();

        // Opened legitimately by the owner…
        $form = Livewire::test(ReceiptForm::class)
            ->set('invoice_id', $invoice->id)
            ->set('amount', 45);

        // …then acted on by someone who may not create one. mount() gates are
        // not gates on their own — Livewire dispatches to methods directly, so
        // save() has to refuse instead of trusting that the page ever opened.
        $this->asAdmin();
        $form->call('save')->assertForbidden();

        $this->assertSame(0, LimoReceipt::query()->count());
    }

    public function test_opening_an_existing_receipt_is_the_owners_alone_too(): void
    {
        $invoice = $this->invoice();
        $receipt = LimoReceipt::query()->create([
            'invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id,
            'date' => now(), 'amount' => 20, 'method' => 'cash',
        ]);

        // Nothing links to this screen any more — the list offers Download and
        // Send instead — so the same rule guards both doors. Touching a receipt
        // by hand is a correction, whichever direction it is reached from.
        $this->asAdmin();
        Livewire::test(ReceiptForm::class, ['id' => $receipt->id])->assertForbidden();

        // The owner can still repair one.
        $this->asOwner();
        Livewire::test(ReceiptForm::class, ['id' => $receipt->id])
            ->assertOk()
            ->set('amount', 25)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(25.0, (float) $receipt->fresh()?->amount, 0.001);
    }
}
