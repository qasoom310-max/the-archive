<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Contacts\Models\Partner;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Models\PosCategory;
use Modules\Pos\Models\PosCondiment;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosOrderLine;
use Modules\Pos\Models\PosPaymentMethod;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosSession;
use Modules\Pos\Services\PosSessionManager;
use Modules\Pos\Support\PosWhatsAppCountries;

#[Layout('components.layouts.app')]
#[Title('Point of Sale')]
final class PosTerminal extends Component
{
    public int $sessionId;

    public int $orderId;

    public string $search = '';

    public ?int $categoryId = null;

    public bool $paying = false;

    /**
     * Condiment picker: which cart line is being edited, and whether the
     * overlay is open. Any active condiment can be toggled onto any line
     * (one global list); the selection is stored as a price/name snapshot
     * on the line.
     */
    public bool $pickingCondiments = false;

    public ?int $condimentLineId = null;

    /**
     * Customer picker (the Odoo-19-style "Choose Customer" list): when
     * true the modal is rendered and the cashier picks an existing
     * Partner or clicks "Create" to switch to the add-customer modal.
     */
    public bool $pickingCustomer = false;

    /**
     * Free-text filter for the customer picker — matches name, phone,
     * or email (case-insensitive LIKE). Empty = show everyone.
     */
    public string $customerSearch = '';

    /**
     * Add-customer modal: when true the overlay is rendered and the
     * cashier fills the form below.
     */
    public bool $addingCustomer = false;

    public string $newCustomerName = '';

    public string $newCustomerCountryCode = PosWhatsAppCountries::DEFAULT_DIAL;

    public string $newCustomerPhone = '';

    public string $newCustomerEmail = '';

    /**
     * Edit mode: when set, the customer modal pre-fills with this Partner's
     * fields and "Save" updates rather than creates. Null = create mode.
     * Visibility of the modal is still controlled by {@see $addingCustomer}.
     */
    public ?int $editingCustomerId = null;

    public ?int $paymentMethodId = null;

    public string $tendered = '';

    public ?int $receiptOrderId = null;

    /**
     * Country dial code for the auto-receipt phone number (e.g. "+973").
     * Always one of {@see PosWhatsAppCountries::COUNTRIES}; defaults to
     * Bahrain since that's the primary deployment.
     */
    public string $countryCode = PosWhatsAppCountries::DEFAULT_DIAL;

    /**
     * Local national-format digits typed by the cashier — no country code,
     * spaces and dashes tolerated and stripped at compose time. Empty =
     * walk-in, no receipt sent.
     */
    public string $localPhone = '';

    public function mount(int $session): void
    {
        $pos = PosSession::query()->findOrFail($session);

        // Single shared register: any ACL-authorised cashier may join the
        // open session — no per-user ownership gate.
        abort_unless($pos->state === SessionState::Opened, 403, 'The register is closed.');
        $this->guard(Permission::Create);

        $this->sessionId = $pos->id;
        $this->orderId = $this->resolveDraftOrder($pos)->id;

        app(PosSessionManager::class)->heartbeat($pos, $this->currentUserId());
    }

    /**
     * Polled by the terminal so the Manage view sees who is live.
     */
    public function heartbeat(): void
    {
        app(PosSessionManager::class)->heartbeat(
            PosSession::query()->findOrFail($this->sessionId),
            $this->currentUserId(),
        );
    }

    private function guard(Permission $permission): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.order', $permission);
    }

    private function currentUserId(): ?int
    {
        $userId = Auth::id();

        return is_int($userId) ? $userId : null;
    }

    private function resolveDraftOrder(PosSession $session): PosOrder
    {
        // Wrap the whole find-or-create in a row-locked transaction. Without
        // it, two terminals opening the same session at the same time can
        // both see "no draft", both compute the same next sequence, and one
        // hits `pos_orders_reference_unique` with a 500. The lock on the
        // session row serialises every cashier on this register so only
        // one is computing the sequence at a time.
        return DB::transaction(function () use ($session): PosOrder {
            PosSession::query()->whereKey($session->id)->lockForUpdate()->first();

            $existing = PosOrder::query()
                ->where('pos_session_id', $session->id)
                ->where('state', OrderState::Draft)
                ->latest('id')
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            // Next sequence = MAX(seq) + 1, NOT count() + 1. Using count()
            // breaks the moment any order is deleted (count goes down but
            // the unique reference column doesn't), reusing the deleted
            // row's number on the next terminal open. Parse the trailing
            // digits off every reference for this session in PHP — small
            // dataset (orders in one session), portable across SQLite/
            // MySQL/Postgres without driver-specific SUBSTRING_INDEX.
            $maxSeq = (int) PosOrder::query()
                ->where('pos_session_id', $session->id)
                ->pluck('reference')
                ->map(static fn (string $r): int => (int) substr($r, (int) strrpos($r, '/') + 1))
                ->max();

            return PosOrder::query()->create([
                'pos_session_id' => $session->id,
                'user_id' => $this->currentUserId(),
                'reference' => sprintf('POS/%d/%04d', $session->id, $maxSeq + 1),
                'state' => OrderState::Draft,
            ]);
        });
    }

    private function order(): PosOrder
    {
        return PosOrder::query()->with('lines', 'partner')->findOrFail($this->orderId);
    }

    public function addProduct(int $productId): void
    {
        $this->guard(Permission::Write);
        $product = PosProduct::query()->findOrFail($productId);
        $order = $this->order();

        // Merge only into a PLAIN line (no discount, no condiments) — a line
        // carrying condiments is distinct, so tapping the product again starts
        // a fresh line rather than silently bumping the condiment'd one.
        $line = $order->lines()
            ->where('pos_product_id', $product->id)
            ->where('discount', 0)
            ->get()
            ->first(static fn (PosOrderLine $l): bool => empty($l->condiments));

        if ($line !== null) {
            $line->qty += 1;
        } else {
            $line = $order->lines()->make([
                'pos_product_id' => $product->id,
                'name' => $product->name,
                'qty' => 1,
                'unit_price' => $product->price,
                'discount' => 0,
                'tax_rate' => $product->tax_rate,
            ]);
        }

        $line->recompute();
        $line->save();
        $order->recalculate();
    }

    /**
     * Adjust a cart line's quantity by one step. `$increment` true = +1,
     * false = −1; reaching 0 removes the line (quantity never goes negative).
     *
     * Global / role-independent: gated only by the standard `pos.order`
     * Write permission every cashier already holds (same guard as
     * addProduct/removeLine) — no Admin-vs-Cashier branching.
     */
    public function updateQuantity(int $lineId, bool $increment): void
    {
        $this->guard(Permission::Write);
        $order = $this->order();
        $line = $order->lines()->whereKey($lineId)->first();

        if ($line === null) {
            return;
        }

        $line->qty = max(0.0, $line->qty + ($increment ? 1.0 : -1.0));

        if ($line->qty <= 0) {
            $line->delete();
        } else {
            $line->recompute();
            $line->save();
        }

        $order->recalculate();
    }

    public function setDiscount(int $lineId, float $percent): void
    {
        $this->guard(Permission::Write);
        $order = $this->order();
        $line = $order->lines()->whereKey($lineId)->first();

        if ($line === null) {
            return;
        }

        $line->discount = max(0.0, min(100.0, $percent));
        $line->recompute();
        $line->save();
        $order->recalculate();
    }

    public function removeLine(int $lineId): void
    {
        $this->guard(Permission::Write);
        $order = $this->order();
        $order->lines()->whereKey($lineId)->delete();
        $order->recalculate();
    }

    /**
     * Attach a free-text note to a cart line — surfaced verbatim on the
     * matching Kitchen Display ticket so the cook sees "no pickle / extra
     * chilli / well-done" without leaving the screen. Empty string clears it.
     */
    public function setLineNotes(int $lineId, string $notes): void
    {
        $this->guard(Permission::Write);
        $order = $this->order();
        $line = $order->lines()->whereKey($lineId)->first();

        if ($line === null) {
            return;
        }

        $trimmed = trim($notes);
        $line->notes = $trimmed === '' ? null : $trimmed;
        $line->save();
    }

    /**
     * Open the condiment picker for a cart line. Any active condiment may be
     * toggled onto any line (one global list).
     */
    public function openCondiments(int $lineId): void
    {
        $this->guard(Permission::Write);

        if ($this->order()->lines()->whereKey($lineId)->exists()) {
            $this->condimentLineId = $lineId;
            $this->pickingCondiments = true;
        }
    }

    public function closeCondiments(): void
    {
        $this->pickingCondiments = false;
        $this->condimentLineId = null;
    }

    /**
     * Toggle a condiment on the line being edited. Stores a price + name
     * SNAPSHOT on the line (so a later catalogue edit can't rewrite a
     * finalised order) and recomputes the line + order totals live.
     */
    public function toggleCondiment(int $condimentId): void
    {
        $this->guard(Permission::Write);

        if ($this->condimentLineId === null) {
            return;
        }

        $order = $this->order();
        $line = $order->lines()->whereKey($this->condimentLineId)->first();

        if ($line === null) {
            return;
        }

        $removed = false;
        $next = [];
        foreach ($line->condiments ?? [] as $condiment) {
            if ((int) ($condiment['id'] ?? 0) === $condimentId) {
                $removed = true; // toggle off

                continue;
            }
            $next[] = $condiment;
        }

        if (! $removed) {
            $condiment = PosCondiment::query()->where('active', true)->find($condimentId);

            if ($condiment === null) {
                return;
            }

            $next[] = [
                'id' => (int) $condiment->id,
                'name' => (string) $condiment->name,
                'price' => round((float) $condiment->price, 2),
            ];
        }

        $line->condiments = $next;
        $line->recompute();
        $line->save();
        $order->recalculate();
    }

    public function clearCustomer(): void
    {
        $order = $this->order();
        $order->partner_id = null;
        $order->save();

        // Removing the customer drops any phone-keyed discount and resets the
        // receipt phone so the cleared customer's number can't linger.
        $this->countryCode = PosWhatsAppCountries::DEFAULT_DIAL;
        $this->localPhone = '';
        $this->syncCustomerDiscount();
    }

    /**
     * Resolve and apply the open per-phone customer discount for the order's
     * current customer. Prefers the attached Partner's stored phone (the
     * "added customer" the admin keyed the discount to); falls back to the
     * phone the cashier typed in the receipt row. Re-fetches the partner
     * relation fresh so a just-saved `partner_id` is reflected.
     */
    private function syncCustomerDiscount(): void
    {
        $order = PosOrder::query()->with('partner')->findOrFail($this->orderId);
        $order->applyCustomerDiscount($this->resolveDiscountPhone($order));
    }

    private function resolveDiscountPhone(PosOrder $order): ?string
    {
        $partner = $order->partner;

        if ($partner !== null) {
            $phone = $partner->phone;

            if ($phone !== null && $phone !== '') {
                return $phone;
            }
        }

        return PosWhatsAppCountries::compose($this->countryCode, $this->localPhone);
    }

    /**
     * Livewire hook: re-evaluate the discount when the cashier types a phone
     * in the receipt row (covers walk-ins with no attached Partner). The
     * attached Partner's phone still wins inside {@see resolveDiscountPhone()}.
     */
    public function updatedLocalPhone(): void
    {
        $this->syncCustomerDiscount();
    }

    /**
     * Open the Odoo-19-style "Choose Customer" picker — list of existing
     * Partners with a live search and a Create button. The cashier either
     * picks a row (attaches that Partner) or clicks Create to switch into
     * the add-customer form modal.
     */
    public function openCustomerPicker(): void
    {
        $this->guard(Permission::Write);
        $this->customerSearch = '';
        $this->pickingCustomer = true;
    }

    public function closeCustomerPicker(): void
    {
        $this->pickingCustomer = false;
        $this->customerSearch = '';
    }

    /**
     * Attach an existing Partner to the current draft order and pre-fill
     * the auto-receipt phone fields from their stored number (if any), so
     * the cashier doesn't retype it in the payment overlay.
     */
    public function pickCustomer(int $partnerId): void
    {
        $this->guard(Permission::Write);
        $partner = Partner::query()->find($partnerId);

        if ($partner === null) {
            return;
        }

        $order = $this->order();
        $order->partner_id = $partner->id;
        $order->save();

        $phone = $partner->phone;
        if ($phone !== null && $phone !== '') {
            [$dial, $local] = $this->splitStoredPhone($phone);
            $this->countryCode = $dial;
            $this->localPhone = $local;
        }

        // Apply any open per-phone discount now that the customer is attached.
        $this->syncCustomerDiscount();
        $this->closeCustomerPicker();
    }

    /**
     * Switch from the picker into the create-customer form. Called from
     * the "Create" button at the top-left of the picker.
     */
    public function startCreateCustomer(): void
    {
        $this->guard(Permission::Write);
        $this->closeCustomerPicker();
        $this->openAddCustomer();
    }

    /**
     * Open the customer form modal in EDIT mode for a specific Partner —
     * reached from the pencil icon on each picker row. Pre-fills name,
     * country dial + local digits, and email from the stored values, then
     * swaps the picker for the form.
     *
     * Gated by `contacts.partner` Write — users without that permission
     * never see the pencil button (view check) and can't reach this code
     * path via wire-replay either.
     */
    public function openEditCustomer(int $partnerId): void
    {
        app(AccessControl::class)->authorize(
            Auth::user(),
            'contacts.partner',
            Permission::Write,
        );

        $partner = Partner::query()->find($partnerId);

        if ($partner === null) {
            return;
        }

        [$dial, $local] = $this->splitStoredPhone($partner->phone ?? '');

        $this->newCustomerName = $partner->name;
        $this->newCustomerCountryCode = $dial;
        $this->newCustomerPhone = $local;
        $this->newCustomerEmail = $partner->email ?? '';
        $this->editingCustomerId = $partner->id;

        $this->closeCustomerPicker();
        $this->addingCustomer = true;
        $this->resetErrorBag();
    }

    /**
     * The modal's Save button calls this — dispatches to create or update
     * based on whether {@see $editingCustomerId} is set. Keeping the two
     * branches as separate methods (rather than one merged code path) so
     * each path's behaviour stays obvious and easy to test in isolation.
     */
    public function saveCustomer(): void
    {
        if ($this->editingCustomerId !== null) {
            $this->saveEditedCustomer();

            return;
        }

        $this->saveNewCustomer();
    }

    /**
     * Persist edits to an existing Partner. Same validation rules as
     * {@see saveNewCustomer()} but does NOT attach to the order — editing
     * doesn't change who's on this cart. If the edited partner happens to
     * already be the cart's customer, re-hydrate the local receipt phone
     * fields so the payment overlay reflects the updated number.
     */
    public function saveEditedCustomer(): void
    {
        app(AccessControl::class)->authorize(
            Auth::user(),
            'contacts.partner',
            Permission::Write,
        );

        if ($this->editingCustomerId === null) {
            return;
        }

        $this->validate([
            'newCustomerName' => ['required', 'string', 'max:200'],
            'newCustomerCountryCode' => ['required', 'string'],
            'newCustomerPhone' => ['required', 'string', 'max:30'],
            'newCustomerEmail' => ['nullable', 'email', 'max:200'],
        ]);

        if (! PosWhatsAppCountries::isValidDial($this->newCustomerCountryCode)) {
            $this->addError('newCustomerCountryCode', __('Please pick a country.'));

            return;
        }

        $composedDigits = PosWhatsAppCountries::compose(
            $this->newCustomerCountryCode,
            $this->newCustomerPhone,
        );

        if ($composedDigits === null) {
            $this->addError('newCustomerPhone', __('Please enter a valid phone number.'));

            return;
        }

        $partner = Partner::query()->find($this->editingCustomerId);

        if ($partner === null) {
            $this->cancelAddCustomer();

            return;
        }

        $localDigits = preg_replace('/\D+/', '', $this->newCustomerPhone) ?? '';
        if (str_starts_with($localDigits, '0')) {
            $localDigits = substr($localDigits, 1);
        }

        $email = trim($this->newCustomerEmail);

        $partner->update([
            'name' => trim($this->newCustomerName),
            'phone' => $this->newCustomerCountryCode . ' ' . $localDigits,
            'email' => $email === '' ? null : $email,
        ]);

        // If the edited customer is the one currently on the cart, the
        // receipt phone might now be stale — refresh local hydration so
        // the payment overlay matches the partner's new phone.
        $order = $this->order();
        if ($order->partner_id === $partner->id) {
            $this->countryCode = $this->newCustomerCountryCode;
            $this->localPhone = $localDigits;
            // The edited phone may now match (or stop matching) a discount.
            $this->syncCustomerDiscount();
        }

        $this->addingCustomer = false;
        $this->resetAddCustomerForm();
    }

    /**
     * Hard-delete a Partner from the database. Reached from the trash
     * icon on each picker row, behind a `wire:confirm` browser dialog.
     *
     * Gated by the standard ACL on `contacts.partner` Unlink — users
     * without that permission don't see the button (see render()), and
     * if they call this directly the authorize() throws → HTTP 403.
     *
     * Order history is preserved: `pos_orders.partner_id` is FK'd with
     * `nullOnDelete()`, so any past orders for this customer keep all
     * their data (totals, payments, lines, chatter), just with no
     * customer link. If the deleted partner happened to be the one
     * attached to the CURRENT draft order, we also reset the locally-
     * hydrated receipt phone so the next pick starts clean.
     */
    public function deleteCustomer(int $partnerId): void
    {
        app(AccessControl::class)->authorize(
            Auth::user(),
            'contacts.partner',
            Permission::Unlink,
        );

        $partner = Partner::query()->find($partnerId);

        if ($partner === null) {
            return;
        }

        $order = $this->order();
        $resetReceiptFields = $order->partner_id === $partner->id;

        $partner->delete();

        if ($resetReceiptFields) {
            // The FK already nulled order->partner_id on the DB side.
            // Clear our local receipt hydration so it doesn't carry the
            // deleted customer's phone into the payment overlay.
            $this->countryCode = PosWhatsAppCountries::DEFAULT_DIAL;
            $this->localPhone = '';
            // ...and drop the discount that customer's phone carried.
            $this->syncCustomerDiscount();
        }
    }

    /**
     * Open the "Add customer" form modal with a freshly-reset form.
     * Public so tests can drive it directly; in production it's reached
     * by clicking Create inside the picker (see {@see startCreateCustomer}).
     */
    public function openAddCustomer(): void
    {
        $this->guard(Permission::Write);
        $this->resetAddCustomerForm();
        $this->addingCustomer = true;
    }

    public function cancelAddCustomer(): void
    {
        $this->addingCustomer = false;
        $this->resetAddCustomerForm();
    }

    /**
     * Validate the modal form, create a new {@see Partner}, attach it to
     * the current order, and pre-fill the auto-receipt phone fields from
     * the same number so the cashier doesn't have to retype it.
     */
    public function saveNewCustomer(): void
    {
        $this->guard(Permission::Write);

        $this->validate([
            'newCustomerName' => ['required', 'string', 'max:200'],
            'newCustomerCountryCode' => ['required', 'string'],
            'newCustomerPhone' => ['required', 'string', 'max:30'],
            'newCustomerEmail' => ['nullable', 'email', 'max:200'],
        ]);

        if (! PosWhatsAppCountries::isValidDial($this->newCustomerCountryCode)) {
            $this->addError('newCustomerCountryCode', __('Please pick a country.'));

            return;
        }

        // Reuse the same compose() the receipt uses so the Partner.phone
        // and the auto-receipt destination can never disagree.
        $composedDigits = PosWhatsAppCountries::compose(
            $this->newCustomerCountryCode,
            $this->newCustomerPhone,
        );

        if ($composedDigits === null) {
            $this->addError('newCustomerPhone', __('Please enter a valid phone number.'));

            return;
        }

        $localDigits = preg_replace('/\D+/', '', $this->newCustomerPhone) ?? '';
        if (str_starts_with($localDigits, '0')) {
            $localDigits = substr($localDigits, 1);
        }

        $email = trim($this->newCustomerEmail);

        $partner = Partner::query()->create([
            'name' => trim($this->newCustomerName),
            // Human-readable international form, matching the seeder convention
            // ("+973 33123456") so the contact list reads naturally.
            'phone' => $this->newCustomerCountryCode . ' ' . $localDigits,
            'email' => $email === '' ? null : $email,
            'is_company' => false,
        ]);

        $order = $this->order();
        $order->partner_id = $partner->id;
        $order->save();

        // Auto-fill the receipt phone fields from the just-created customer
        // so the cashier doesn't retype the same number two minutes later
        // in the payment overlay.
        $this->countryCode = $this->newCustomerCountryCode;
        $this->localPhone = $localDigits;

        // Apply any open per-phone discount for the new customer's number.
        $this->syncCustomerDiscount();

        $this->addingCustomer = false;
        $this->resetAddCustomerForm();
    }

    private function resetAddCustomerForm(): void
    {
        $this->newCustomerName = '';
        $this->newCustomerCountryCode = PosWhatsAppCountries::DEFAULT_DIAL;
        $this->newCustomerPhone = '';
        $this->newCustomerEmail = '';
        $this->editingCustomerId = null;
        $this->resetErrorBag();
    }

    public function startPayment(): void
    {
        $order = $this->order();

        if ($order->lines()->count() === 0 || $order->total <= 0) {
            return;
        }

        $this->paymentMethodId = PosPaymentMethod::query()
            ->where('active', true)->orderBy('sequence')->value('id');

        // If the cashier already entered a phone earlier on this same draft
        // (e.g. opened the payment overlay, cancelled, reopened) we left
        // `customer_phone` on the row. Re-hydrate the two fields so the
        // overlay reflects that state instead of resetting to blank.
        if ($order->customer_phone !== null && $this->localPhone === '') {
            [$dial, $local] = $this->splitStoredPhone($order->customer_phone);
            $this->countryCode = $dial;
            $this->localPhone = $local;
        }

        // Lock in the discount from the best-known phone before computing the
        // tendered default, so the "Total" the cashier collects already
        // reflects it. syncCustomerDiscount() persists the recalculated total.
        $this->syncCustomerDiscount();

        $fresh = $this->order();
        $this->tendered = number_format(max(0.0, $fresh->total - $fresh->paymentsTotal()), 2, '.', '');

        $this->paying = true;
    }

    /**
     * Best-effort split of a stored phone string back into its (dial,
     * local) parts for the dropdown + input. Tolerant of multiple input
     * shapes:
     *   - `97333123456`   — order's `customer_phone` (digits only)
     *   - `+973 33123456` — partner's `phone` (with '+' and space)
     *   - `+973-33-123-456` — any human formatting
     * All non-digits are stripped first, then the longest matching
     * country digit-prefix wins. Falls back to Bahrain default + the
     * full digit string if no prefix matches.
     *
     * @return array{0: string, 1: string}
     */
    private function splitStoredPhone(string $stored): array
    {
        $digits = preg_replace('/\D+/', '', $stored) ?? '';

        foreach (PosWhatsAppCountries::all() as $country) {
            if (str_starts_with($digits, $country['digits'])) {
                return [$country['dial'], substr($digits, strlen($country['digits']))];
            }
        }

        return [PosWhatsAppCountries::DEFAULT_DIAL, $digits];
    }

    public function addPayment(): void
    {
        $this->guard(Permission::Write);
        $order = $this->order();
        $amount = round((float) $this->tendered, 2);

        if ($this->paymentMethodId === null || $amount <= 0) {
            return;
        }

        $method = PosPaymentMethod::query()->findOrFail($this->paymentMethodId);
        $order->registerPayment($method, $amount);

        $remaining = round($order->total - $order->paymentsTotal(), 2);
        $this->tendered = $remaining > 0
            ? number_format($remaining, 2, '.', '')
            : '';
    }

    public function validateOrder(): void
    {
        $this->guard(Permission::Write);
        $order = $this->order();

        // Already finalised — just show the receipt again (no re-consume).
        if ($order->state === OrderState::Done) {
            $this->receiptOrderId = $order->id;
            $this->paying = false;

            return;
        }

        if (! $order->isFullyPaid()) {
            return;
        }

        // Compose the cashier-typed (countryCode, localPhone) into the
        // Meta-ready international digits and store it on the order BEFORE
        // finalizeSale() fires PosOrderPaid — the auto-receipt listener
        // reads `$order->customer_phone` to decide whether to send.
        // compose() returns null if either part is empty / non-digit only,
        // which the listener treats as "walk-in, skip silently".
        $order->customer_phone = PosWhatsAppCountries::compose(
            $this->countryCode,
            $this->localPhone,
        );

        // "Processed By" attribution — stamp the finalising cashier at
        // validate time. resolveDraftOrder() already stamped the creator
        // when the cart was opened; overwriting here makes the column
        // reflect who actually CLOSED the sale, which is what shows up
        // in the orders list. Same person in single-cashier sessions;
        // for shared registers a different cashier finalising takes the
        // credit (still audited via Chatter regardless).
        $order->user_id = $this->currentUserId();
        $order->save();

        $order->finalizeSale();

        $this->receiptOrderId = $order->id;
        $this->paying = false;
    }

    public function newOrder(): void
    {
        $session = PosSession::query()->findOrFail($this->sessionId);
        $this->orderId = $this->resolveDraftOrder($session)->id;
        $this->receiptOrderId = null;
        $this->paying = false;
        $this->search = '';
        $this->countryCode = PosWhatsAppCountries::DEFAULT_DIAL;
        $this->localPhone = '';
    }

    /**
     * The selected category plus all its descendants — so clicking a
     * parent shows the whole subtree's products (Odoo-style).
     *
     * @return list<int>
     */
    private function categorySubtreeIds(): array
    {
        if ($this->categoryId === null) {
            return [];
        }

        $category = PosCategory::query()->find($this->categoryId);

        return $category !== null ? $category->subtreeIds() : [$this->categoryId];
    }

    /**
     * @return Collection<int, PosProduct>
     */
    private function products(): Collection
    {
        return PosProduct::query()
            ->with('recipeLines.component')
            ->where('active', true)
            ->when($this->categoryId !== null, fn ($q) => $q->whereIn('pos_category_id', $this->categorySubtreeIds()))
            ->when($this->search !== '', fn ($q) => $q->where(function ($w): void {
                $w->where('name', 'like', '%' . $this->search . '%')
                    ->orWhere('barcode', $this->search);
            }))
            ->orderBy('name')
            ->limit(60)
            ->get();
    }

    /**
     * Filtered list of Partners for the picker modal. Matches on name,
     * phone or email (case-insensitive LIKE); empty search returns the
     * first 50 alphabetically. Limit is plenty for an in-store register;
     * if the contact list grows past that, swap in pagination.
     *
     * @return Collection<int, Partner>
     */
    private function customerListQuery(): Collection
    {
        return Partner::query()
            ->when($this->customerSearch !== '', function ($q): void {
                $needle = '%' . $this->customerSearch . '%';
                $q->where(function ($w) use ($needle): void {
                    $w->where('name', 'like', $needle)
                        ->orWhere('phone', 'like', $needle)
                        ->orWhere('email', 'like', $needle);
                });
            })
            ->orderBy('name')
            ->limit(50)
            ->get();
    }

    public function render(): View
    {
        $order = $this->order();

        return view('pos::terminal', [
            'order' => $order,
            'session' => PosSession::query()->with('user')->findOrFail($this->sessionId),
            'cashier' => Auth::user(),
            'lines' => $order->lines()->latest('id')->get(),
            'products' => $this->products(),
            'categories' => PosCategory::query()->whereNull('parent_id')
                ->orderBy('sequence')->orderBy('name')->get(),
            'subCategories' => $this->categoryId !== null
                ? PosCategory::query()->where('parent_id', $this->categoryId)
                    ->orderBy('sequence')->orderBy('name')->get()
                : new Collection(),
            'paymentMethods' => PosPaymentMethod::query()->where('active', true)->orderBy('sequence')->get(),
            // Only run the partners query when the picker is open — saves a
            // table scan on every keystroke in the cart.
            'customerList' => $this->pickingCustomer
                ? $this->customerListQuery()
                : new Collection(),
            'canEditCustomers' => app(AccessControl::class)->allows(
                Auth::user(),
                'contacts.partner',
                Permission::Write,
            ),
            'canDeleteCustomers' => app(AccessControl::class)->allows(
                Auth::user(),
                'contacts.partner',
                Permission::Unlink,
            ),
            // Only query condiments while the picker is open. The line being
            // edited carries its selection (rendered as ticked rows).
            'condiments' => $this->pickingCondiments
                ? PosCondiment::query()->where('active', true)
                    ->orderBy('sequence')->orderBy('name')->get()
                : new Collection(),
            'condimentLine' => $this->pickingCondiments && $this->condimentLineId !== null
                ? $order->lines()->whereKey($this->condimentLineId)->first()
                : null,
            'receipt' => $this->receiptOrderId !== null
                ? PosOrder::query()->with('lines', 'payments.method', 'partner')->find($this->receiptOrderId)
                : null,
            'now' => Carbon::now(),
            'whatsappCountries' => PosWhatsAppCountries::all(),
        ]);
    }
}
