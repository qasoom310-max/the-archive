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
 * Writing a receipt by hand is reserved to whoever may vouch that money
 * actually arrived: the owner, and the Accountant — the same pairing
 * {@see \App\Models\User::canConfirmPayments()} already uses for confirming
 * one. A regular admin, who is neither, may not.
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

    private function asAccountant(): User
    {
        // A real Accountant is provisioned with Read/Write/Create on the apps
        // they need, same as any other staff role — the flag alone is not
        // what gets them past the base ACL gate every screen still has.
        $this->grantEveryone('limousine.receipt');
        $accountant = User::factory()->create(['is_admin' => false, 'is_accountant' => true]);
        $this->actingAs($accountant);

        return $accountant;
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

    public function test_the_owner_and_accountant_are_offered_the_new_receipt_button(): void
    {
        $this->asOwner();
        Livewire::test(Receipts::class)->assertSee(__('New receipt'));

        $this->asAccountant();
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

    /**
     * The payment-portal callback already stamps a settled receipt with
     * method "online" ({@see \Modules\Limousine\Http\Controllers\
     * PaymentCallbackController}) — this offers the same value on the
     * hand-written form too, for a Tap payment taken outside the portal.
     */
    public function test_a_receipt_can_be_recorded_as_online_tap(): void
    {
        $this->asOwner();
        $invoice = $this->invoice();

        Livewire::test(ReceiptForm::class)
            ->set('invoice_id', $invoice->id)
            ->set('amount', 45)
            ->set('method', 'online')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('online', LimoReceipt::query()->sole()->method);
    }

    public function test_an_accountant_can_also_write_one_by_hand(): void
    {
        $this->asAccountant();
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

    public function test_opening_an_existing_receipt_follows_the_same_rule_as_creating_one(): void
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

        // So can the Accountant.
        $this->asAccountant();
        Livewire::test(ReceiptForm::class, ['id' => $receipt->id])
            ->assertOk()
            ->set('amount', 30)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(30.0, (float) $receipt->fresh()?->amount, 0.001);
    }

    /**
     * The list itself never linked to the repair screen at all — an owner or
     * Accountant who spotted a receipt logged under the wrong method (cash
     * instead of the BenefitPay it actually arrived as) had no way to reach
     * ReceiptForm's edit path without typing the URL by hand. An Edit action
     * on each row, gated the same as the screen it opens, closes that gap.
     */
    public function test_the_list_offers_an_edit_link_that_actually_corrects_the_method(): void
    {
        $invoice = $this->invoice();
        $receipt = LimoReceipt::query()->create([
            'invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id,
            'date' => now(), 'amount' => 20, 'method' => 'cash',
        ]);

        $this->asOwner();
        Livewire::test(Receipts::class)
            ->set('tab', 'all')
            ->assertSee(__('Edit'))
            ->assertSeeHtml('/app/limousine/receipt/' . $receipt->id);

        Livewire::test(ReceiptForm::class, ['id' => $receipt->id])
            ->set('method', 'benefit')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('benefit', $receipt->fresh()?->method);

        // A regular admin still gets no Edit link — the list mirrors exactly
        // who ReceiptForm itself would let in.
        $this->asAdmin();
        Livewire::test(Receipts::class)->set('tab', 'all')->assertDontSee(__('Edit'));
    }

    public function test_the_invoice_field_is_searchable(): void
    {
        $this->asOwner();
        $invoice = $this->invoice();

        $form = Livewire::test(ReceiptForm::class);
        $form->assertSee("role=\"combobox\"", false)
            ->assertSee(__("Search invoice number or customer…"))
            ->assertSee((string) $invoice->reference)
            ->assertSee("Helen Friberg");
        $form->set("invoice_id", $invoice->id)
            ->set("amount", 45)
            ->set("method", "cash")
            ->call("save")
            ->assertHasNoErrors();

        $this->assertSame(1, LimoReceipt::query()->count());
    }
}
