<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Contacts\Models\Partner;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Livewire\PosTerminal;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosSession;
use Modules\Pos\Support\PosWhatsAppCountries;
use Tests\TestCase;

/**
 * The terminal's customer panel uses an Odoo-19-style flow: "+ Customer"
 * button opens a picker modal listing existing Partners (filterable);
 * the picker has a "Create" button that switches to the add-customer
 * form. Picking a row attaches that Partner; creating one creates + attaches.
 * Either way, the auto-receipt phone is pre-filled from the customer's
 * phone so the cashier doesn't retype it in the payment overlay.
 */
final class PosAddCustomerTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function installPos(): void
    {
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

    public function test_open_and_cancel_toggle_the_modal_state(): void
    {
        $this->installPos();
        $session = $this->openSession();

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->assertSet('addingCustomer', false)
            ->call('openAddCustomer')
            ->assertSet('addingCustomer', true)
            ->set('newCustomerName', 'Cleared on cancel')
            ->call('cancelAddCustomer')
            ->assertSet('addingCustomer', false)
            ->assertSet('newCustomerName', ''); // form was reset
    }

    public function test_save_creates_partner_attaches_it_and_fills_receipt_phone(): void
    {
        $this->installPos();
        $session = $this->openSession();

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('openAddCustomer')
            ->set('newCustomerName', '  Ahmed Khalil  ') // whitespace trimmed
            ->set('newCustomerCountryCode', '+973')
            ->set('newCustomerPhone', '0 33 123 456') // leading 0 + spaces
            ->set('newCustomerEmail', 'ahmed@example.com')
            ->call('saveNewCustomer')
            ->assertSet('addingCustomer', false)
            ->assertSet('newCustomerName', '')      // reset
            ->assertSet('countryCode', '+973')      // receipt pre-filled
            ->assertSet('localPhone', '33123456');  // leading 0 stripped, spaces gone

        $partner = Partner::query()->sole();
        $this->assertSame('Ahmed Khalil', $partner->name);
        $this->assertSame('+973 33123456', $partner->phone);
        $this->assertSame('ahmed@example.com', $partner->email);
        $this->assertFalse($partner->is_company);

        $order = PosOrder::query()->where('pos_session_id', $session->id)->sole();
        $this->assertSame($partner->id, $order->partner_id);
    }

    public function test_empty_email_is_stored_as_null_not_empty_string(): void
    {
        $this->installPos();
        $session = $this->openSession();

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('openAddCustomer')
            ->set('newCustomerName', 'Walk-in regular')
            ->set('newCustomerPhone', '33999999')
            ->set('newCustomerEmail', '')
            ->call('saveNewCustomer')
            ->assertHasNoErrors();

        $this->assertNull(Partner::query()->sole()->email);
    }

    public function test_missing_name_fails_validation(): void
    {
        $this->installPos();
        $session = $this->openSession();

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('openAddCustomer')
            ->set('newCustomerName', '')
            ->set('newCustomerPhone', '33123456')
            ->call('saveNewCustomer')
            ->assertHasErrors(['newCustomerName' => 'required'])
            ->assertSet('addingCustomer', true); // modal stays open

        $this->assertSame(0, Partner::query()->count());
    }

    public function test_missing_phone_fails_validation(): void
    {
        $this->installPos();
        $session = $this->openSession();

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('openAddCustomer')
            ->set('newCustomerName', 'No-phone Nora')
            ->set('newCustomerPhone', '')
            ->call('saveNewCustomer')
            ->assertHasErrors(['newCustomerPhone' => 'required']);

        $this->assertSame(0, Partner::query()->count());
    }

    public function test_phone_thats_only_punctuation_fails_compose(): void
    {
        $this->installPos();
        $session = $this->openSession();

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('openAddCustomer')
            ->set('newCustomerName', 'Hyphen Hugo')
            ->set('newCustomerPhone', '---')
            ->call('saveNewCustomer')
            ->assertHasErrors('newCustomerPhone');

        $this->assertSame(0, Partner::query()->count());
    }

    public function test_invalid_email_fails_validation(): void
    {
        $this->installPos();
        $session = $this->openSession();

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('openAddCustomer')
            ->set('newCustomerName', 'Bad-email Bob')
            ->set('newCustomerPhone', '33555000')
            ->set('newCustomerEmail', 'not-an-email')
            ->call('saveNewCustomer')
            ->assertHasErrors(['newCustomerEmail' => 'email']);

        $this->assertSame(0, Partner::query()->count());
    }

    public function test_default_country_code_in_modal_is_bahrain(): void
    {
        $this->installPos();
        $session = $this->openSession();

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->assertSet('newCustomerCountryCode', PosWhatsAppCountries::DEFAULT_DIAL);
    }

    public function test_picker_lists_existing_customers_only_when_open(): void
    {
        $this->installPos();
        $session = $this->openSession();
        Partner::query()->create(['name' => 'afshaan', 'phone' => '+973 33851247']);
        Partner::query()->create(['name' => 'Arleen', 'email' => 'cheeky@x.test', 'city' => 'Tubli']);

        $c = Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->assertSet('pickingCustomer', false);
        // Closed → no list rendered (saves a query per render); the
        // assertion above is enough — the +/Customer span split makes a
        // simple `assertSee('+ Customer')` brittle vs. HTML structure.

        $c->call('openCustomerPicker')
            ->assertSet('pickingCustomer', true)
            ->assertSee('Choose Customer')
            ->assertSee('afshaan')
            ->assertSee('Arleen')
            ->assertSee('+973 33851247')
            ->assertSee('cheeky@x.test');
    }

    public function test_picker_search_filters_by_name_phone_or_email(): void
    {
        $this->installPos();
        $session = $this->openSession();
        Partner::query()->create(['name' => 'Ahmed', 'phone' => '+973 33111111']);
        Partner::query()->create(['name' => 'Bashir', 'phone' => '+973 33222222']);
        Partner::query()->create(['name' => 'Cleo', 'email' => 'cleo@x.test']);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('openCustomerPicker')
            ->set('customerSearch', 'bash')
            ->assertSee('Bashir')
            ->assertDontSee('Ahmed')
            ->assertDontSee('Cleo')
            ->set('customerSearch', '33111111')
            ->assertSee('Ahmed')
            ->assertDontSee('Bashir')
            ->set('customerSearch', 'cleo@')
            ->assertSee('Cleo')
            ->assertDontSee('Ahmed');
    }

    public function test_picking_existing_customer_attaches_and_fills_receipt_phone(): void
    {
        $this->installPos();
        $session = $this->openSession();
        $partner = Partner::query()->create([
            'name' => 'Existing Eli',
            'phone' => '+966 50 999 8888', // KSA, with human spaces
        ]);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('openCustomerPicker')
            ->call('pickCustomer', $partner->id)
            ->assertSet('pickingCustomer', false)        // modal closed
            ->assertSet('countryCode', '+966')           // matched KSA dial
            ->assertSet('localPhone', '509998888');      // digits-only local

        $order = PosOrder::query()->where('pos_session_id', $session->id)->sole();
        $this->assertSame($partner->id, $order->partner_id);
    }

    public function test_picking_nonexistent_partner_is_a_silent_noop(): void
    {
        $this->installPos();
        $session = $this->openSession();

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('openCustomerPicker')
            ->call('pickCustomer', 999999); // ID doesn't exist

        $order = PosOrder::query()->where('pos_session_id', $session->id)->sole();
        $this->assertNull($order->partner_id);
    }

    public function test_create_button_in_picker_swaps_to_add_form(): void
    {
        $this->installPos();
        $session = $this->openSession();

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('openCustomerPicker')
            ->assertSet('pickingCustomer', true)
            ->assertSet('addingCustomer', false)
            ->call('startCreateCustomer')
            ->assertSet('pickingCustomer', false)
            ->assertSet('addingCustomer', true);
    }

    public function test_discard_closes_picker_and_clears_search(): void
    {
        $this->installPos();
        $session = $this->openSession();

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('openCustomerPicker')
            ->set('customerSearch', 'something')
            ->call('closeCustomerPicker')
            ->assertSet('pickingCustomer', false)
            ->assertSet('customerSearch', '');
    }

    public function test_admin_can_hard_delete_a_customer_from_the_picker(): void
    {
        $this->installPos();
        $session = $this->openSession();
        $alice = Partner::query()->create(['name' => 'Alice', 'phone' => '+973 33000001']);
        $bob = Partner::query()->create(['name' => 'Bob', 'phone' => '+973 33000002']);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('openCustomerPicker')
            ->assertSee('Alice')
            ->assertSee('Bob')
            ->call('deleteCustomer', $alice->id)
            ->assertDontSee('Alice') // re-render's customer list query reflects the delete
            ->assertSee('Bob');

        $this->assertSame(0, Partner::query()->whereKey($alice->id)->count());
        $this->assertSame(1, Partner::query()->whereKey($bob->id)->count());
    }

    public function test_delete_keeps_past_orders_but_nulls_their_partner_id(): void
    {
        $this->installPos();
        $session = $this->openSession();
        $repeatCustomer = Partner::query()->create(['name' => 'Repeat Riza', 'phone' => '+973 33000003']);

        // Past order belonging to this customer — simulates a completed sale.
        // Marked Done so the terminal's resolveDraftOrder() doesn't adopt it
        // as the live cart (a Draft with a hardcoded total but no lines would
        // otherwise be recalculated to 0 the moment any recompute fires).
        $pastOrder = PosOrder::query()->create([
            'pos_session_id' => $session->id,
            'partner_id' => $repeatCustomer->id,
            'reference' => 'POS/0001',
            'state' => OrderState::Done,
            'total' => 12.50,
        ]);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('openCustomerPicker')
            ->call('deleteCustomer', $repeatCustomer->id);

        // Customer is gone…
        $this->assertSame(0, Partner::query()->whereKey($repeatCustomer->id)->count());

        // …but the order survives, with the FK nulled by ON DELETE SET NULL.
        $pastOrder->refresh();
        $this->assertNull($pastOrder->partner_id);
        $this->assertEqualsWithDelta(12.50, (float) $pastOrder->total, 0.001);
    }

    public function test_deleting_currently_attached_customer_resets_receipt_fields(): void
    {
        $this->installPos();
        $session = $this->openSession();
        $partner = Partner::query()->create(['name' => 'Saudi Sam', 'phone' => '+966 50 111 2222']);

        $c = Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('openCustomerPicker')
            ->call('pickCustomer', $partner->id)
            ->assertSet('countryCode', '+966')
            ->assertSet('localPhone', '501112222');

        // Re-open the picker and delete this same customer.
        $c->call('openCustomerPicker')
            ->call('deleteCustomer', $partner->id)
            ->assertSet('countryCode', PosWhatsAppCountries::DEFAULT_DIAL) // reset to Bahrain
            ->assertSet('localPhone', '');                                 // wiped
    }

    public function test_deleting_a_different_customer_does_not_touch_receipt_fields(): void
    {
        $this->installPos();
        $session = $this->openSession();
        $attached = Partner::query()->create(['name' => 'Pat', 'phone' => '+973 33444555']);
        $stranger = Partner::query()->create(['name' => 'Stranger', 'phone' => '+966 50 999 8888']);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('openCustomerPicker')
            ->call('pickCustomer', $attached->id)
            ->call('openCustomerPicker')
            ->call('deleteCustomer', $stranger->id)
            ->assertSet('countryCode', '+973')      // still the picked one
            ->assertSet('localPhone', '33444555');
    }

    public function test_open_edit_customer_prefills_form_and_switches_to_edit_mode(): void
    {
        $this->installPos();
        $session = $this->openSession();
        $partner = Partner::query()->create([
            'name' => 'Old Name',
            'phone' => '+966 50 111 2222', // KSA, human-formatted
            'email' => 'old@example.com',
        ]);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('openCustomerPicker')
            ->call('openEditCustomer', $partner->id)
            ->assertSet('pickingCustomer', false)
            ->assertSet('addingCustomer', true)
            ->assertSet('editingCustomerId', $partner->id)
            ->assertSet('newCustomerName', 'Old Name')
            ->assertSet('newCustomerCountryCode', '+966')
            ->assertSet('newCustomerPhone', '501112222')
            ->assertSet('newCustomerEmail', 'old@example.com')
            ->assertSee('Edit customer'); // dynamic modal title
    }

    public function test_save_in_edit_mode_updates_existing_partner_does_not_create_new(): void
    {
        $this->installPos();
        $session = $this->openSession();
        $partner = Partner::query()->create([
            'name' => 'Before',
            'phone' => '+973 33000000',
            'email' => 'before@x.test',
        ]);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('openCustomerPicker')
            ->call('openEditCustomer', $partner->id)
            ->set('newCustomerName', 'After')
            ->set('newCustomerCountryCode', '+971')
            ->set('newCustomerPhone', '50 1234567')
            ->set('newCustomerEmail', 'after@x.test')
            ->call('saveCustomer')
            ->assertHasNoErrors()
            ->assertSet('addingCustomer', false)
            ->assertSet('editingCustomerId', null) // reset back to create mode
            ->assertSet('newCustomerName', '');    // form cleared

        // Same row, updated values — no second partner created.
        $this->assertSame(1, Partner::query()->count());
        $partner->refresh();
        $this->assertSame('After', $partner->name);
        $this->assertSame('+971 501234567', $partner->phone);
        $this->assertSame('after@x.test', $partner->email);
    }

    public function test_edit_does_not_attach_customer_to_the_order(): void
    {
        $this->installPos();
        $session = $this->openSession();
        $partner = Partner::query()->create(['name' => 'Lonely', 'phone' => '+973 33000000']);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('openCustomerPicker')
            ->call('openEditCustomer', $partner->id)
            ->set('newCustomerName', 'Lonelier')
            ->call('saveCustomer');

        // Cart still has no customer — editing isn't selecting.
        $order = PosOrder::query()->where('pos_session_id', $session->id)->sole();
        $this->assertNull($order->partner_id);
    }

    public function test_editing_currently_attached_customer_rehydrates_receipt_phone(): void
    {
        $this->installPos();
        $session = $this->openSession();
        $partner = Partner::query()->create(['name' => 'Pat', 'phone' => '+973 33000000']);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('openCustomerPicker')
            ->call('pickCustomer', $partner->id)             // attach + hydrate receipt
            ->assertSet('countryCode', '+973')
            ->assertSet('localPhone', '33000000')
            ->call('openCustomerPicker')
            ->call('openEditCustomer', $partner->id)
            ->set('newCustomerCountryCode', '+966')
            ->set('newCustomerPhone', '50 999 8888')
            ->call('saveCustomer')
            ->assertSet('countryCode', '+966')               // refreshed from edit
            ->assertSet('localPhone', '509998888');
    }

    public function test_editing_a_different_customer_does_not_touch_receipt_phone(): void
    {
        $this->installPos();
        $session = $this->openSession();
        $attached = Partner::query()->create(['name' => 'Attached', 'phone' => '+973 33111111']);
        $other = Partner::query()->create(['name' => 'Other', 'phone' => '+966 50 222 2222']);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('openCustomerPicker')
            ->call('pickCustomer', $attached->id)
            ->call('openCustomerPicker')
            ->call('openEditCustomer', $other->id)
            ->set('newCustomerPhone', '50 333 4444')
            ->call('saveCustomer')
            ->assertSet('countryCode', '+973')               // attached unchanged
            ->assertSet('localPhone', '33111111');
    }

    public function test_save_in_create_mode_still_creates_after_save_dispatcher(): void
    {
        // saveCustomer() dispatches to saveNewCustomer when editingCustomerId
        // is null — verify the create path still works through the new entry point.
        $this->installPos();
        $session = $this->openSession();

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('openAddCustomer')
            ->set('newCustomerName', 'Brand New')
            ->set('newCustomerPhone', '33 555 7777')
            ->call('saveCustomer')
            ->assertHasNoErrors()
            ->assertSet('addingCustomer', false);

        $this->assertSame(1, Partner::query()->count());
        $this->assertSame('Brand New', Partner::query()->sole()->name);
    }

    public function test_non_write_user_cannot_edit_customer_and_button_is_hidden(): void
    {
        $this->installPos();
        $this->seed(\Database\Seeders\PosSeeder::class);
        $session = $this->openSession();

        $cashier = User::factory()->create(['is_admin' => false]);
        $posUserGroup = \App\Models\Auth\Group::query()->where('code', 'pos_user')->sole();
        $cashier->groups()->attach($posUserGroup->id);
        $this->actingAs($cashier);

        $partner = Partner::query()->create(['name' => 'Untouchable', 'phone' => '+973 33000000']);

        // Trash gating already covers `contacts.partner` Unlink — this
        // test pins the parallel Write gate for the pencil icon.
        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('openCustomerPicker')
            ->assertViewHas('canEditCustomers', false);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('openEditCustomer', $partner->id)
            ->assertForbidden();
    }

    public function test_non_unlink_user_cannot_delete_and_button_is_hidden(): void
    {
        $this->installPos();
        // PosSeeder creates the `pos_user` group + ACLs (pos.session +
        // pos.order operate, NO contacts.partner Unlink). Without this
        // the cashier can't even mount the terminal because `pos.order`
        // Create is missing.
        $this->seed(\Database\Seeders\PosSeeder::class);
        $session = $this->openSession();

        $cashier = User::factory()->create(['is_admin' => false]);
        $posUserGroup = \App\Models\Auth\Group::query()->where('code', 'pos_user')->sole();
        $cashier->groups()->attach($posUserGroup->id);
        $this->actingAs($cashier);

        Partner::query()->create(['name' => 'Untouchable', 'phone' => '+973 33000000']);

        // The flag controlling the trash button's visibility must be false
        // for this user — so the icon never renders in the picker for them.
        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('openCustomerPicker')
            ->assertSet('pickingCustomer', true)
            ->assertViewHas('canDeleteCustomers', false);

        // And calling the action directly is rejected with 403 — server-side
        // enforcement, can't be bypassed by replaying the wire request.
        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('deleteCustomer', Partner::query()->where('name', 'Untouchable')->sole()->id)
            ->assertForbidden();

        $this->assertSame(1, Partner::query()->count()); // unchanged
    }
}
