<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use App\Erp\Business\Feature;
use App\Erp\Business\Features;
use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\PrepStatus;
use Modules\Pos\Enums\SalesChannel;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Models\PosCategory;
use Modules\Pos\Models\PosCondiment;
use Modules\Pos\Models\PosCustomerDiscount;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosOrderLine;
use Modules\Pos\Models\PosPaymentMethod;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosSession;
use Modules\Pos\Models\PosTable;
use Modules\Pos\Services\HappyHour;
use Modules\Pos\Services\KitchenRouter;
use Modules\Pos\Services\PosSessionManager;
use Modules\Pos\Support\PosWhatsAppCountries;

#[Layout('components.layouts.app')]
#[Title('Point of Sale')]
final class PosTerminal extends Component
{
    use WithFileUploads;

    public int $sessionId;

    /**
     * Proof-of-payment photo the cashier attaches in the payment popup (a
     * Benefit / bank-transfer screenshot). Transient temp upload — stored to
     * the order's `payment_proof_path` in {@see updatedPaymentProof()} and then
     * cleared. Only used when the PaymentProof feature is on.
     *
     * @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null
     */
    public $paymentProof = null;

    /** The table this terminal is serving — null = walk-in / quick sale. */
    public ?int $tableId = null;

    public int $orderId;

    public string $search = '';

    public ?int $categoryId = null;

    /**
     * "Uncategorised" chip: show only products with no category. Without it a
     * product that was saved without a category is unreachable from the grid —
     * it sits under no chip at all, and the cashier can only find it by typing
     * its name. Mutually exclusive with {@see $categoryId}.
     */
    public bool $uncategorised = false;

    /**
     * How many products the grid renders at once. Anything past this is NOT
     * silently dropped — the cashier is told the list is capped and to search
     * or pick a category (see `productsTruncated` in render()).
     */
    private const GRID_LIMIT = 60;

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
     * Phone-entry modal for the customer discount. The cashier types a phone
     * number; a matching per-phone discount applies to the order. No customer
     * record or phone is saved — the same number is reused for the WhatsApp
     * receipt at checkout (the {@see $countryCode}/{@see $localPhone} fields).
     */
    public bool $enteringPhone = false;

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

    /**
     * Sales channel of the current order: 'shop' (walk-in) or 'remote'
     * (phone / WhatsApp / delivery). Only meaningful when the Remote sales
     * feature is on; the toggle is hidden otherwise.
     */
    public string $channel = 'shop';

    /** Remote-order delivery capture (mirrored onto the order as it's typed). */
    public string $customerName = '';

    public string $deliveryAddress = '';

    /** Delivery cost WE absorb (our expense). */
    public string $deliveryFee = '';

    /** Delivery charged TO the customer — added to their bill (revenue). */
    public string $deliveryCharge = '';

    public string $deliveryReference = '';

    /** Set when a remote order is missing its required customer name / phone. */
    public string $channelError = '';

    public function mount(int $session, ?int $table = null): void
    {
        $pos = PosSession::query()->findOrFail($session);

        // Single shared register: any ACL-authorised cashier may join the
        // open session — no per-user ownership gate.
        abort_unless($pos->state === SessionState::Opened, 403, 'The register is closed.');
        $this->guard(Permission::Create);

        // Bind to a table when one is given (must exist). Null = walk-in.
        if ($table !== null) {
            abort_unless(PosTable::query()->whereKey($table)->exists(), 404);
            $this->tableId = $table;
        }

        $this->sessionId = $pos->id;
        $this->orderId = $this->resolveDraftOrder($pos)->id;
        $this->hydrateChannelFields();

        // Arriving from the Remote sales dashboard's "New remote order" button
        // (?channel=remote) pre-selects the remote channel on this draft.
        if (request()->query('channel') === 'remote' && Features::enabled(Feature::RemoteSales)) {
            $this->setChannel('remote');
        }

        app(PosSessionManager::class)->heartbeat($pos, $this->currentUserId());
    }

    /** Pull the current order's channel + delivery details into the form. */
    private function hydrateChannelFields(): void
    {
        $order = $this->order();
        $this->channel = $order->channel->value;
        $this->customerName = $order->customer_name ?? '';
        $this->deliveryAddress = $order->delivery_address ?? '';
        $this->deliveryReference = $order->delivery_reference ?? '';
        $this->deliveryFee = $order->delivery_fee > 0
            ? rtrim(rtrim(number_format((float) $order->delivery_fee, 2), '0'), '.')
            : '';
        $this->deliveryCharge = $order->delivery_charge > 0
            ? rtrim(rtrim(number_format((float) $order->delivery_charge, 2), '0'), '.')
            : '';
    }

    /**
     * Flip the current order between the shop (walk-in) and remote (delivery)
     * channel. Returning to shop drops any delivery fee so the total is clean.
     */
    public function setChannel(string $channel): void
    {
        $this->guard(Permission::Write);
        if (! Features::enabled(Feature::RemoteSales)) {
            return;
        }

        $channel = $channel === 'remote' ? 'remote' : 'shop';
        $this->channel = $channel;
        $this->channelError = '';

        $order = $this->order();
        $order->channel = SalesChannel::from($channel);
        if ($channel === 'shop') {
            $order->delivery_fee = 0;
            $order->delivery_charge = 0;
            $this->deliveryFee = '';
            $this->deliveryCharge = '';
        }
        $order->save();
        $order->recalculate();
    }

    public function updatedCustomerName(): void
    {
        $order = $this->order();
        $order->customer_name = trim($this->customerName) !== '' ? trim($this->customerName) : null;
        $order->save();
        $this->channelError = '';
    }

    public function updatedDeliveryAddress(): void
    {
        $order = $this->order();
        $order->delivery_address = trim($this->deliveryAddress) !== '' ? trim($this->deliveryAddress) : null;
        $order->save();
    }

    public function updatedDeliveryReference(): void
    {
        $order = $this->order();
        $order->delivery_reference = trim($this->deliveryReference) !== '' ? trim($this->deliveryReference) : null;
        $order->save();
    }

    public function updatedDeliveryFee(): void
    {
        $order = $this->order();
        $order->delivery_fee = max(0.0, round((float) $this->deliveryFee, 2));
        $order->save();
        $order->recalculate();
    }

    /** Delivery amount charged to the customer — added to their bill. */
    public function updatedDeliveryCharge(): void
    {
        $order = $this->order();
        $order->delivery_charge = max(0.0, round((float) $this->deliveryCharge, 2));
        $order->save();
        $order->recalculate();
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

            // Scope the draft to THIS table (or the table-less walk-in lane)
            // so every table keeps its own running order independently.
            $existing = PosOrder::query()
                ->where('pos_session_id', $session->id)
                ->where('state', OrderState::Draft)
                ->when(
                    $this->tableId === null,
                    fn ($q) => $q->whereNull('pos_table_id'),
                    fn ($q) => $q->where('pos_table_id', $this->tableId),
                )
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
                'pos_table_id' => $this->tableId,
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

        // Sweileh Café afternoon happy hour (12:00–18:00 Bahrain): shisha is
        // capped at the deal price, food gets a % off, drinks are excluded.
        // Evaluated now, at ring-up — a no-op everywhere else / outside the window.
        $pricing = app(HappyHour::class)->priceLine($product);

        // Merge only into an IDENTICAL line — same product, same unit price,
        // same discount, no condiments — so repeated taps stack. A line with a
        // manual discount, a happy-hour price, or condiments stays distinct
        // (matched in PHP with rounding to dodge float equality in SQL).
        $line = $order->lines()
            ->where('pos_product_id', $product->id)
            ->get()
            ->first(static fn (PosOrderLine $l): bool => empty($l->condiments)
                && round((float) $l->unit_price, 3) === round($pricing['unit_price'], 3)
                && round((float) $l->discount, 3) === round($pricing['discount'], 3));

        if ($line !== null) {
            $line->qty += 1;
        } else {
            $line = $order->lines()->make([
                'pos_product_id' => $product->id,
                'name' => $product->name,
                'qty' => 1,
                'unit_price' => $pricing['unit_price'],
                'discount' => $pricing['discount'],
                'tax_rate' => $product->tax_rate,
            ]);
        }

        $line->recompute();
        $line->save();
        $order->recalculate();

        // Auto-send to the kitchen / shisha screens the moment an item is
        // added — POSTPAID dine-in flow only: a kitchen-routed line goes
        // straight to the KDS, no payment first. In PREPAID mode (the default)
        // items fire to the kitchen on payment instead (PosOrderPaid →
        // QueueLinesForKitchen), so the eager route is skipped here.
        // No-op for products whose category has no station; idempotent.
        if (Features::enabled(Feature::Postpaid)) {
            app(KitchenRouter::class)->route($order);
        }
    }

    /**
     * Resolve a scanned barcode to an active product and add it to the cart.
     * Driven by the camera scanner overlay (Odoo-style): each successful scan
     * adds one unit and the cashier keeps scanning. Dispatches `scan-hit` /
     * `scan-miss` so the overlay can flash feedback without a full re-render.
     */
    public function scanBarcode(string $barcode): void
    {
        $this->guard(Permission::Write);

        $code = trim($barcode);
        if ($code === '') {
            return;
        }

        $product = PosProduct::query()
            ->where('active', true)
            ->where('barcode', $code)
            ->first();

        if ($product === null) {
            $this->dispatch('scan-miss', barcode: $code);

            return;
        }

        $this->addProduct((int) $product->id);
        $this->dispatch('scan-hit', name: (string) $product->name);
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
     * Condiments offered for the line currently in the picker: ONLY the add-ons
     * assigned to the product on its product page (the per-product condiment
     * field). Category-scoped / global condiments do NOT auto-appear — the
     * picker is driven exclusively by the product's own assignment. A product
     * with no assignment shows an empty picker. Empty when the picker is closed.
     *
     * @return Collection<int, PosCondiment>
     */
    private function condimentOptions(): Collection
    {
        if (! $this->pickingCondiments || $this->condimentLineId === null) {
            return new Collection();
        }

        $line = $this->order()->lines()->whereKey($this->condimentLineId)->first();
        $assignedIds = $line?->product?->condiments->pluck('id')->all() ?? [];

        if ($assignedIds === []) {
            return new Collection();
        }

        return PosCondiment::query()
            ->where('active', true)
            ->whereIn('id', $assignedIds)
            ->orderBy('sequence')->orderBy('name')->get();
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

    /**
     * Clear the entered discount phone — drops any applied discount and the
     * receipt number. No customer record is ever stored, so there's nothing
     * else to detach.
     */
    public function clearPhone(): void
    {
        $this->guard(Permission::Write);

        $this->countryCode = PosWhatsAppCountries::DEFAULT_DIAL;
        $this->localPhone = '';
        $this->enteringPhone = false;

        $order = $this->order();
        $order->customer_phone = null;
        $order->save();

        $this->syncCustomerDiscount();
    }

    /**
     * Open / close the phone-entry modal behind the "+ Customer discount"
     * button. The cashier types a phone; a matching per-phone discount
     * applies live. No customer record is created.
     */
    public function openPhoneEntry(): void
    {
        $this->guard(Permission::Write);
        $this->enteringPhone = true;
    }

    public function closePhoneEntry(): void
    {
        // Re-sync on close so a country change (entangled, deferred) that
        // didn't trigger updatedCountryCode is still reflected.
        $this->syncCustomerDiscount();
        $this->enteringPhone = false;
    }

    /**
     * Resolve and apply the open per-phone customer discount from the phone
     * the cashier typed. No customer record is involved — the discount is
     * keyed purely on the number (the same one used for the WhatsApp receipt
     * at checkout).
     */
    private function syncCustomerDiscount(): void
    {
        $this->order()->applyCustomerDiscount($this->resolveDiscountPhone());
    }

    private function resolveDiscountPhone(): ?string
    {
        return PosWhatsAppCountries::compose($this->countryCode, $this->localPhone);
    }

    /**
     * Livewire hooks: re-evaluate the discount whenever the cashier changes
     * the phone number or its country code.
     */
    public function updatedLocalPhone(): void
    {
        $this->syncCustomerDiscount();
    }

    public function updatedCountryCode(): void
    {
        $this->syncCustomerDiscount();
    }

    /**
     * Is this order "green" — kitchen finished, or it has no kitchen items at
     * all? Green = no line is still pending or preparing (mirrors the floor
     * plan's `kitchenStatus()` ready bucket). Drives the dine-in payment gate.
     */
    private function orderKitchenReady(PosOrder $order): bool
    {
        $statuses = $order->lines()->get()->pluck('prep_status')->filter();

        return ! $statuses->contains(PrepStatus::Pending)
            && ! $statuses->contains(PrepStatus::Preparing);
    }

    /**
     * The live prepaid-credit balance on the order's matched discount (0 when
     * there's none). Shown in the customer panel so the cashier sees how much
     * store credit the customer has left.
     */
    private function customerBalance(PosOrder $order): float
    {
        if ($order->customer_discount_id === null) {
            return 0.0;
        }

        $discount = PosCustomerDiscount::query()->find($order->customer_discount_id);

        return $discount !== null ? max(0.0, (float) $discount->prepaid_balance) : 0.0;
    }

    /**
     * May the cashier take payment right now? Always needs items + a positive
     * total. In POSTPAID mode a DINE-IN table additionally requires the kitchen
     * to be done (green) — "Pay now" stays locked while the order is red (sent)
     * or yellow (preparing); walk-in / quick sale is ungated. In PREPAID mode
     * (the default) payment is always allowed — the order fires to the kitchen
     * once paid, so there is nothing to wait for.
     */
    private function canPay(PosOrder $order): bool
    {
        // Block only an empty cart. A total of exactly 0 is payable — prepaid
        // credit (or a 100% discount) can legitimately cover the whole bill,
        // and the cashier still needs to close it out.
        if ($order->lines->isEmpty() || $order->total < 0) {
            return false;
        }

        if (! Features::enabled(Feature::Postpaid)) {
            return true;
        }

        // The postpaid gate waits for the kitchen to mark the order ready. With
        // the kitchen & shisha screens turned off there is nobody to do that, so
        // the gate would never open — don't hold payment on it.
        if (! Features::enabled(Feature::Kitchen)) {
            return true;
        }

        return $this->tableId === null || $this->orderKitchenReady($order);
    }

    public function startPayment(): void
    {
        $order = $this->order();

        if (! $this->canPay($order)) {
            return;
        }

        // A remote / delivery order must name the customer and carry a phone
        // before it can be taken to payment.
        if ($order->isRemote()) {
            if (trim($this->customerName) === '' || trim($this->localPhone) === '') {
                $this->channelError = __('Enter the customer name and phone for a delivery order.');

                return;
            }
            $this->channelError = '';
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
     * Confirm a remote / delivery order to be paid on delivery (COD): commit it
     * to the fulfillment queue without taking payment now. The money is
     * collected later from the Remote sales dashboard.
     */
    public function confirmCod(): void
    {
        $this->guard(Permission::Write);
        $order = $this->order();

        if (! $order->isRemote() || ! $this->canPay($order)) {
            return;
        }

        if (trim($this->customerName) === '' || trim($this->localPhone) === '') {
            $this->channelError = __('Enter the customer name and phone for a delivery order.');

            return;
        }
        $this->channelError = '';

        $order->customer_phone = PosWhatsAppCountries::compose($this->countryCode, $this->localPhone);
        $order->user_id = $this->currentUserId();
        $order->save();

        $order->confirmUnpaid();

        $this->receiptOrderId = $order->id;
        $this->paying = false;
    }

    /**
     * A proof-of-payment photo was picked in the payment popup: validate it,
     * store it on the public disk, and stamp its path on the (draft) order so
     * the owner can review it later. Replacing a proof deletes the old file so
     * we don't orphan uploads. Gated by the PaymentProof feature.
     */
    public function updatedPaymentProof(): void
    {
        $this->guard(Permission::Write);

        if (! Features::enabled(Feature::PaymentProof)) {
            $this->paymentProof = null;

            return;
        }

        // 8 MB covers a phone screenshot of a bank / Benefit confirmation; the
        // file is stored as-is (no image processing), so no memory blow-up.
        $this->validate([
            'paymentProof' => ['image', 'max:8192', 'mimes:png,jpg,jpeg,webp'],
        ]);

        $order = $this->order();

        if ($order->hasPaymentProof()) {
            Storage::disk('public')->delete((string) $order->payment_proof_path);
        }

        $path = $this->paymentProof->store('pos/payment-proofs', 'public');
        $order->payment_proof_path = is_string($path) ? $path : null;
        $order->save();

        $this->paymentProof = null;
    }

    /** Remove the attached proof-of-payment photo from the current order. */
    public function removePaymentProof(): void
    {
        $this->guard(Permission::Write);
        $order = $this->order();

        if ($order->hasPaymentProof()) {
            Storage::disk('public')->delete((string) $order->payment_proof_path);
            $order->payment_proof_path = null;
            $order->save();
        }

        $this->paymentProof = null;
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

        // isPaid (not isFullyPaid) so a fully-credit-covered 0-total order —
        // which needs no tendered cash — can be closed too.
        if (! $order->isPaid()) {
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

    /**
     * Hand the current order to the shared split-order modal.
     */
    public function openSplit(): void
    {
        abort_unless(Features::enabled(Feature::SplitOrder), 404);

        $this->dispatch('open-split-order', orderId: $this->orderId);
    }

    /**
     * The modal split lines off this draft — re-render so the cart reflects
     * what's left. (Empty body: presence of the listener triggers Livewire's
     * round-trip and the render() reload of the order.)
     */
    #[On('order-split')]
    public function refreshAfterSplit(): void
    {
    }

    /**
     * "New order" from the receipt: a dine-in table sends the cashier back to
     * the floor plan to pick the next table (the just-paid table is now free —
     * starting another order should go through table selection, NOT silently
     * reopen the same one). Walk-in / quick sale has no table, so it just
     * starts a fresh order in place to keep counter service flowing.
     */
    public function finishToFloor(): void
    {
        if ($this->tableId !== null) {
            $this->redirect(url('/app/pos/session/' . $this->sessionId . '/floor'), navigate: true);

            return;
        }

        $this->newOrder();
    }

    public function newOrder(): void
    {
        $session = PosSession::query()->findOrFail($this->sessionId);
        $this->orderId = $this->resolveDraftOrder($session)->id;
        $this->receiptOrderId = null;
        $this->paying = false;
        $this->enteringPhone = false;
        $this->search = '';
        $this->countryCode = PosWhatsAppCountries::DEFAULT_DIAL;
        $this->localPhone = '';
        $this->channelError = '';
        $this->hydrateChannelFields();
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
    /**
     * Every active product matching the current chip + search — WITHOUT the
     * display cap, so render() can count them and tell the cashier when the
     * grid is showing only part of the catalogue.
     *
     * @return \Illuminate\Database\Eloquent\Builder<PosProduct>
     */
    private function productQuery(): Builder
    {
        return PosProduct::query()
            ->where('active', true)
            // "Uncategorised" chip — products saved with no category. They belong
            // to no other chip, so this is the only way to browse to them.
            ->when($this->uncategorised, fn ($q) => $q->whereNull('pos_category_id'))
            ->when(
                ! $this->uncategorised && $this->categoryId !== null,
                fn ($q) => $q->whereIn('pos_category_id', $this->categorySubtreeIds()),
            )
            ->when($this->search !== '', fn ($q) => $q->where(function ($w): void {
                $w->where('name', 'like', '%' . $this->search . '%')
                    ->orWhere('barcode', $this->search);
            }));
    }

    /**
     * @return Collection<int, PosProduct>
     */
    private function products(): Collection
    {
        return $this->productQuery()
            ->with('recipeLines.component')
            ->orderBy('name')
            ->limit(self::GRID_LIMIT)
            ->get();
    }

    /** Pick a category chip (null = All). Clears the Uncategorised chip. */
    public function selectCategory(?int $categoryId): void
    {
        $this->categoryId = $categoryId;
        $this->uncategorised = false;
    }

    /** Show only products that have no category. */
    public function selectUncategorised(): void
    {
        $this->categoryId = null;
        $this->uncategorised = true;
    }

    public function render(): View
    {
        $order = $this->order();

        $products = $this->products();
        // How many products actually match vs how many we can show. The grid used
        // to just `limit(60)`, so product #61 (alphabetically) was invisible with
        // no hint — a cashier would swear the product "isn't there".
        $matching = $this->productQuery()->count();

        $canPay = $this->canPay($order);
        // Dine-in table with items but the kitchen still working — drives the
        // "waiting for the kitchen" hint under the locked Pay now button.
        $kitchenBusy = ! $canPay
            && $this->tableId !== null
            && $order->lines->isNotEmpty()
            && $order->total > 0;

        return view('pos::terminal', [
            'order' => $order,
            'canPay' => $canPay,
            'kitchenBusy' => $kitchenBusy,
            'table' => $this->tableId !== null
                ? PosTable::query()->with('floor')->find($this->tableId)
                : null,
            'session' => PosSession::query()->with('user')->findOrFail($this->sessionId),
            'cashier' => Auth::user(),
            'lines' => $order->lines()->latest('id')->get(),
            'products' => $products,
            // Grid is capped — say so rather than silently hiding the rest.
            'productsShown' => $products->count(),
            'productsMatching' => $matching,
            'productsTruncated' => $matching > $products->count(),
            // Only offer the "Uncategorised" chip when such products exist.
            'uncategorisedCount' => PosProduct::query()
                ->where('active', true)->whereNull('pos_category_id')->count(),
            'categories' => PosCategory::query()->where('active', true)->whereNull('parent_id')
                ->orderBy('sequence')->orderBy('name')->get(),
            'subCategories' => $this->categoryId !== null
                ? PosCategory::query()->where('active', true)->where('parent_id', $this->categoryId)
                    ->orderBy('sequence')->orderBy('name')->get()
                : new Collection(),
            'paymentMethods' => PosPaymentMethod::query()->where('active', true)->orderBy('sequence')->get(),
            // Only query condiments while the picker is open. Scoped to the
            // edited line's product category (+ global condiments). The line
            // carries its own selection (rendered as ticked rows).
            'condiments' => $this->condimentOptions(),
            'condimentLine' => $this->pickingCondiments && $this->condimentLineId !== null
                ? $order->lines()->whereKey($this->condimentLineId)->first()
                : null,
            'receipt' => $this->receiptOrderId !== null
                ? PosOrder::query()->with('lines', 'payments.method', 'partner')->find($this->receiptOrderId)
                : null,
            'now' => Carbon::now(),
            'whatsappCountries' => PosWhatsAppCountries::all(),
            'proofEnabled' => Features::enabled(Feature::PaymentProof),
            // Sweileh Café late-night deal is live → show the cashier a banner so
            // they understand why shisha / food prices dropped.
            'happyHour' => app(HappyHour::class)->active(),
            'happyHourShishaPrice' => HappyHour::SHISHA_PRICE,
            'happyHourFoodPercent' => HappyHour::FOOD_DISCOUNT_PERCENT,
            // Live prepaid-credit balance on the matched discount (0 when none),
            // so the cashier can see how much store credit the customer has left.
            'customerBalance' => $this->customerBalance($order),
        ]);
    }
}
