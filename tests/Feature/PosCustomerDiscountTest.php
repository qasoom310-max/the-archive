<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Modules\Contacts\Models\Partner;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Events\PosOrderPaid;
use Modules\Pos\Listeners\RenewCustomerDiscount;
use Modules\Pos\Livewire\PosTerminal;
use Modules\Pos\Models\PosCustomerDiscount;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosPaymentMethod;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosSession;
use Tests\TestCase;

/**
 * Per-phone open customer discounts: an admin assigns a discount % to a
 * phone number; when the cashier adds that customer at the register, the
 * percentage comes off the whole order total. Managed admin-only.
 */
final class PosCustomerDiscountTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('pos');
    }

    private function openSession(): PosSession
    {
        return PosSession::query()->create([
            'reference' => 'POS-S/0001',
            'state' => SessionState::Opened,
            'opening_cash' => 50.0,
            'opened_at' => now(),
        ]);
    }

    public function test_find_for_phone_matches_normalised_variants(): void
    {
        $rule = PosCustomerDiscount::query()->create([
            'phone' => '33123456',
            'discount_percent' => 10,
            'active' => true,
        ]);

        // Exact normalised match.
        $this->assertSame($rule->id, PosCustomerDiscount::findForPhone('33123456')?->id);
        // Stored local number is a suffix of a register phone carrying the
        // country code (and the human "+973 33123456" form).
        $this->assertSame($rule->id, PosCustomerDiscount::findForPhone('97333123456')?->id);
        $this->assertSame($rule->id, PosCustomerDiscount::findForPhone('+973 33123456')?->id);
        // Leading trunk zero is dropped before comparing.
        $this->assertSame($rule->id, PosCustomerDiscount::findForPhone('033123456')?->id);

        // No relation to a different number.
        $this->assertNull(PosCustomerDiscount::findForPhone('99999999'));
    }

    public function test_inactive_discount_is_never_matched(): void
    {
        PosCustomerDiscount::query()->create([
            'phone' => '33123456',
            'discount_percent' => 10,
            'active' => false,
        ]);

        $this->assertNull(PosCustomerDiscount::findForPhone('33123456'));
    }

    public function test_percentage_is_clamped_to_a_sane_range_on_save(): void
    {
        $rule = PosCustomerDiscount::query()->create([
            'phone' => '33123456',
            'discount_percent' => 1000,
        ]);

        $this->assertSame(100.0, $rule->fresh()?->discount_percent);
    }

    public function test_adding_a_customer_with_a_matching_phone_applies_the_discount(): void
    {
        $session = $this->openSession();
        $product = PosProduct::query()->create(['name' => 'Coffee', 'price' => 10, 'tax_rate' => 0, 'active' => true]);
        PosCustomerDiscount::query()->create(['phone' => '+973 33123456', 'discount_percent' => 10, 'active' => true]);
        $partner = Partner::query()->create(['name' => 'Abu Ali', 'phone' => '+973 33123456', 'is_company' => false]);

        $component = Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('addProduct', $product->id);

        $order = PosOrder::query()->where('pos_session_id', $session->id)->firstOrFail();
        $this->assertSame(10.0, (float) $order->total);

        $component->call('pickCustomer', $partner->id);

        $order->refresh();
        $this->assertSame(10.0, (float) $order->customer_discount_percent);
        $this->assertSame(1.0, (float) $order->customer_discount_total);
        $this->assertSame(9.0, (float) $order->total);
    }

    public function test_discount_persists_as_more_products_are_added_then_clears_when_customer_removed(): void
    {
        $session = $this->openSession();
        $product = PosProduct::query()->create(['name' => 'Coffee', 'price' => 10, 'tax_rate' => 0, 'active' => true]);
        PosCustomerDiscount::query()->create(['phone' => '33123456', 'discount_percent' => 10, 'active' => true]);
        $partner = Partner::query()->create(['name' => 'Abu Ali', 'phone' => '+973 33123456', 'is_company' => false]);

        $component = Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('addProduct', $product->id)
            ->call('pickCustomer', $partner->id)
            ->call('addProduct', $product->id); // gross now 20

        $order = PosOrder::query()->where('pos_session_id', $session->id)->firstOrFail();
        $this->assertSame(2.0, (float) $order->customer_discount_total);
        $this->assertSame(18.0, (float) $order->total);

        // Removing the customer drops the discount entirely.
        $component->call('clearCustomer');
        $order->refresh();
        $this->assertSame(0.0, (float) $order->customer_discount_percent);
        $this->assertSame(0.0, (float) $order->customer_discount_total);
        $this->assertSame(20.0, (float) $order->total);
    }

    public function test_discount_management_is_admin_only(): void
    {
        $access = app(AccessControl::class);
        $admin = User::factory()->create(['is_admin' => true]);
        $cashier = User::factory()->create(['is_admin' => false]);

        // Admin bypasses ACL; a non-admin with no grant is denied by default.
        $this->assertTrue($access->allows($admin, 'pos.customer_discount', Permission::Read));
        $this->assertFalse($access->allows($cashier, 'pos.customer_discount', Permission::Read));
        $this->assertFalse($access->allows($cashier, 'pos.customer_discount', Permission::Write));
    }

    public function test_discount_gets_a_90_day_window_on_creation(): void
    {
        $discount = PosCustomerDiscount::query()->create([
            'phone' => '33123456',
            'discount_percent' => 10,
        ]);

        $this->assertNotNull($discount->expires_at);
        // ~90 days out from activation (allow a day of slack either side).
        $this->assertTrue($discount->expires_at?->between(now()->addDays(89), now()->addDays(91)) ?? false);
    }

    public function test_find_for_phone_skips_a_lapsed_discount(): void
    {
        $discount = PosCustomerDiscount::query()->create(['phone' => '33123456', 'discount_percent' => 10]);

        // Mass update bypasses the saving hook, so the past expiry sticks
        // (simulating 90 days elapsing with no purchase).
        PosCustomerDiscount::query()->whereKey($discount->id)->update(['expires_at' => now()->subDay()]);

        $this->assertNull(PosCustomerDiscount::findForPhone('33123456'));
    }

    public function test_a_paid_order_renews_the_discount_window(): void
    {
        // The renewal listener boots with the module in production; mid-test
        // install skips PosServiceProvider::boot(), so wire it by hand.
        Event::listen(PosOrderPaid::class, [RenewCustomerDiscount::class, 'handle']);

        $session = $this->openSession();
        PosPaymentMethod::query()->create(['name' => 'Cash', 'is_cash' => true, 'sequence' => 10]);
        $product = PosProduct::query()->create(['name' => 'Coffee', 'price' => 10, 'tax_rate' => 0, 'active' => true]);
        $discount = PosCustomerDiscount::query()->create(['phone' => '+973 33123456', 'discount_percent' => 10, 'active' => true]);
        $partner = Partner::query()->create(['name' => 'Abu Ali', 'phone' => '+973 33123456', 'is_company' => false]);

        // Window about to lapse — a renewal will visibly push it out to ~90 days.
        PosCustomerDiscount::query()->whereKey($discount->id)->update(['expires_at' => now()->addDays(3)]);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('addProduct', $product->id)
            ->call('pickCustomer', $partner->id)
            ->call('startPayment')
            ->call('addPayment')      // tenders the discounted total (9.00)
            ->call('validateOrder');  // finalizes → fires PosOrderPaid

        $order = PosOrder::query()->where('pos_session_id', $session->id)->firstOrFail();
        $this->assertSame(9.0, (float) $order->total); // 10 − 10%

        $discount->refresh();
        $this->assertTrue($discount->expires_at?->greaterThan(now()->addDays(80)) ?? false);
    }

    public function test_deactivate_lapsed_flips_active_off_for_expired_rows_only(): void
    {
        $live = PosCustomerDiscount::query()->create(['phone' => '33111111', 'discount_percent' => 10]);
        $lapsed = PosCustomerDiscount::query()->create(['phone' => '33222222', 'discount_percent' => 10]);
        PosCustomerDiscount::query()->whereKey($lapsed->id)->update(['expires_at' => now()->subDay()]);

        $this->assertSame(1, PosCustomerDiscount::deactivateLapsed());
        $this->assertTrue((bool) $live->fresh()?->active);    // still in window
        $this->assertFalse((bool) $lapsed->fresh()?->active);  // swept inactive
    }

    public function test_re_enabling_a_lapsed_discount_starts_a_fresh_window(): void
    {
        $discount = PosCustomerDiscount::query()->create(['phone' => '33123456', 'discount_percent' => 10]);

        // Simulate a lapsed + swept discount (expired, active off).
        PosCustomerDiscount::query()->whereKey($discount->id)
            ->update(['expires_at' => now()->subDays(5), 'active' => false]);

        $discount->refresh();
        $discount->active = true;
        $discount->save(); // saving hook restarts the 90-day clock

        $this->assertTrue($discount->expires_at?->greaterThan(now()->addDays(80)) ?? false);
    }
}
