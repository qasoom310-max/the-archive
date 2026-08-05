<?php

declare(strict_types=1);

namespace Modules\Pos\Models;

use App\Erp\Chatter\Chatterable;
use App\Erp\Chatter\HasChatter;
use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Registry\FieldDefinition;
use App\Erp\Registry\ModelDefinition;
use App\Erp\Registry\ViewDefinition;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Contacts\Models\Partner;
use Modules\Pos\Enums\FulfillmentStatus;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\SalesChannel;
use Modules\Pos\Events\PosOrderPaid;
use Modules\Pos\Services\PosInventoryBridge;

/**
 * @property int $id
 * @property string $reference
 * @property int $pos_session_id
 * @property int|null $pos_table_id
 * @property int|null $guest_count
 * @property int|null $partner_id
 * @property int|null $user_id
 * @property OrderState $state
 * @property float $subtotal
 * @property float $tax_total
 * @property float $total
 * @property float $paid_total
 * @property float $change_due
 * @property float $customer_discount_percent
 * @property float $customer_discount_total
 * @property int|null $customer_discount_id  Which discount row the prepaid credit belongs to
 * @property float $credit_applied  Prepaid credit this order consumes (drawn from the discount's balance)
 * @property bool $credit_consumed  True once the wallet has been decremented (double-spend guard)
 * @property bool $components_consumed
 * @property SalesChannel $channel Walk-in shop sale vs a remote / delivery order
 * @property string|null $customer_phone International-format digits (no '+'), e.g. "97333123456"
 * @property string|null $customer_name Remote customer's name (no Partner record needed)
 * @property string|null $payment_proof_path Optional proof-of-payment photo (public disk)
 * @property string|null $delivery_address Remote delivery address (free text)
 * @property float $delivery_fee Delivery cost WE absorb — our expense, NOT on the customer's bill
 * @property float $delivery_charge Delivery charged TO the customer — added to the total (revenue)
 * @property int|null $pos_settlement_id Payout that brought this order's money into our account
 * @property FulfillmentStatus|null $fulfillment_status Delivery pipeline (remote orders only)
 * @property string|null $delivery_reference Courier / delivery-note number (remote orders)
 * @property string|null $notes Order-level free-text note (e.g. set when split off another order)
 * @property Carbon|null $ordered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PosOrder extends Model implements Chatterable, DefinesIrModel
{
    use HasChatter;

    protected $table = 'pos_orders';

    /**
     * Always eager-load the cashier so the list view's `processed_by`
     * accessor doesn't N+1 on every row.
     *
     * @var list<string>
     */
    protected $with = ['user'];

    /** @var list<string> */
    protected $fillable = [
        'reference', 'pos_session_id', 'pos_table_id', 'guest_count',
        'partner_id', 'user_id', 'state', 'channel',
        'subtotal', 'tax_total', 'total', 'paid_total', 'change_due',
        'customer_discount_percent', 'customer_discount_total',
        'customer_discount_id', 'credit_applied', 'credit_consumed',
        'components_consumed', 'customer_phone', 'customer_name', 'payment_proof_path',
        'delivery_address', 'delivery_fee', 'delivery_charge', 'pos_settlement_id',
        'fulfillment_status', 'delivery_reference',
        'notes', 'ordered_at',
    ];

    /**
     * A fresh order defaults to the walk-in shop channel with no delivery fee.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'channel' => 'shop',
        'delivery_fee' => 0,
        'delivery_charge' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => OrderState::class,
            'channel' => SalesChannel::class,
            'fulfillment_status' => FulfillmentStatus::class,
            'subtotal' => 'float',
            'tax_total' => 'float',
            'total' => 'float',
            'paid_total' => 'float',
            'change_due' => 'float',
            'delivery_fee' => 'float',
            'delivery_charge' => 'float',
            'customer_discount_percent' => 'float',
            'customer_discount_total' => 'float',
            'customer_discount_id' => 'integer',
            'credit_applied' => 'float',
            'credit_consumed' => 'boolean',
            'components_consumed' => 'boolean',
            'ordered_at' => 'datetime',
            'pos_table_id' => 'integer',
            'guest_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PosSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }

    /**
     * The table this order is seated at (null = walk-in / quick sale).
     *
     * @return BelongsTo<PosTable, $this>
     */
    public function table(): BelongsTo
    {
        return $this->belongsTo(PosTable::class, 'pos_table_id');
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    /**
     * The cashier who created the order (audit / per-user revenue).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany<PosOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PosOrderLine::class, 'pos_order_id');
    }

    /**
     * @return HasMany<PosPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(PosPayment::class, 'pos_order_id');
    }

    /**
     * "Processed By" accessor — surfaces the cashier's display name (or
     * '—' for unattributed orders) so the metadata-driven List view can
     * render the column without needing a real `processed_by` SQL column.
     *
     * Sorting on this column routes through the arch-level `sort_field`
     * override to `user_id` (the real indexed column) — see PosOrder's
     * irModelDefinition list view.
     */
    public function getProcessedByAttribute(): string
    {
        if ($this->user_id === null) {
            return '—';
        }

        // `$with = ['user']` eager-loads this relation on the list view's
        // paginated query, so no N+1 here for the typical case. Fall back
        // to an explicit lookup if a caller built the model without the
        // relation pre-loaded (defensive). Plain if-blocks rather than
        // `?->name ?? '—'` because Larastan infers the magic relation
        // accessor as non-null and rejects the nullsafe form.
        $user = $this->getRelation('user');

        if ($user instanceof User) {
            return $user->name;
        }

        $fresh = User::query()->find($this->user_id);

        if ($fresh !== null) {
            return $fresh->name;
        }

        return '—';
    }

    /**
     * Recompute order totals from its lines. The customer benefit is applied in
     * two stages, in this order:
     *   1. **Prepaid credit** ({@see $credit_applied}) draws down the customer's
     *      wallet at FULL price — the goods it covers are effectively free.
     *   2. The customer **discount %** then applies to WHATEVER GOODS REMAIN
     *      after the credit — i.e. only what the customer actually pays out of
     *      pocket, which is exactly "after the balance reaches 0 they pay with
     *      the discount".
     * `subtotal`/`tax_total` stay the raw line sums for display; the credit and
     * the discount each show as their own line and only the final `total` moves.
     * The wallet itself is decremented once, at checkout ({@see consumeCustomerCredit()}).
     */
    public function recalculate(): void
    {
        $lines = $this->lines()->get();

        $this->subtotal = round((float) $lines->sum('subtotal'), 2);
        $this->tax_total = round((float) $lines->sum('tax_amount'), 2);

        $gross = round((float) $lines->sum('total'), 2);
        $percent = max(0.0, min(100.0, $this->customer_discount_percent));

        // Stage 1 — prepaid credit. While the wallet hasn't been drawn yet
        // (draft), preview against its LIVE balance; once consumed the stored
        // amount is frozen so a Done order never re-reads a changed balance.
        if (! $this->credit_consumed) {
            $available = $this->availableCredit();
            $this->credit_applied = round(min($available, $gross), 2);
        }
        $creditApplied = round(min((float) $this->credit_applied, $gross), 2);

        // Stage 2 — the % applies to the goods left after the credit.
        $remainingGoods = round($gross - $creditApplied, 2);
        $this->customer_discount_total = round($remainingGoods * $percent / 100, 2);

        // Two delivery amounts, deliberately different:
        //   · delivery_fee     — OUR cost (we pay the driver). Booked as an
        //     operating expense (see RecordDeliveryCostInJournal); NEVER on the
        //     customer total.
        //   · delivery_charge  — what the CUSTOMER pays for delivery (an urgent
        //     request, or an offer without free delivery). Added to the total as
        //     revenue. Not taxed (a flat charge on top). Credit does not cover it.
        $this->total = round($remainingGoods - $this->customer_discount_total + (float) $this->delivery_charge, 2);
        $this->save();
    }

    /**
     * The prepaid credit currently available to this order — the live balance of
     * the matched discount row (0 when none). Read at recalculate time so the
     * preview always reflects the real wallet.
     */
    private function availableCredit(): float
    {
        if ($this->customer_discount_id === null) {
            return 0.0;
        }

        $discount = PosCustomerDiscount::query()->find($this->customer_discount_id);

        return $discount !== null ? max(0.0, (float) $discount->prepaid_balance) : 0.0;
    }

    /**
     * Draw this order's {@see $credit_applied} out of the matched discount's
     * wallet — ONCE (guarded by {@see $credit_consumed}). Reconciles the applied
     * amount to what the wallet could actually cover, in case the live balance
     * dropped below the previewed figure. Called inside the finalise transaction.
     */
    public function consumeCustomerCredit(): void
    {
        if ($this->credit_consumed) {
            return;
        }

        $this->credit_consumed = true;

        if ($this->customer_discount_id === null || (float) $this->credit_applied <= 0.0) {
            $this->credit_applied = 0.0;

            return;
        }

        $discount = PosCustomerDiscount::query()->find($this->customer_discount_id);
        $this->credit_applied = $discount !== null
            ? $discount->drawCredit((float) $this->credit_applied)
            : 0.0;
    }

    /** A remote / delivery order (vs a walk-in shop sale). */
    public function isRemote(): bool
    {
        return $this->channel === SalesChannel::Remote;
    }

    /**
     * The payout that brought this order's money into our account (null while
     * it is still held by the delivery company).
     *
     * @return BelongsTo<PosSettlement, $this>
     */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(PosSettlement::class, 'pos_settlement_id');
    }

    /** Whether a proof-of-payment photo is attached to this order. */
    public function hasPaymentProof(): bool
    {
        return $this->payment_proof_path !== null && $this->payment_proof_path !== '';
    }

    /**
     * Public URL of the proof-of-payment photo, or null when none is attached
     * or the stored file has since gone missing (so a stale path renders no
     * broken image).
     */
    public function paymentProofUrl(): ?string
    {
        if (! $this->hasPaymentProof()) {
            return null;
        }

        $disk = Storage::disk('public');

        return $disk->exists((string) $this->payment_proof_path)
            ? $disk->url((string) $this->payment_proof_path)
            : null;
    }

    /**
     * Advance a remote order one step along the delivery pipeline
     * (new → packed → out for delivery → delivered). No-op once delivered or
     * for a shop order.
     */
    public function advanceFulfillment(): void
    {
        if (! $this->isRemote() || $this->fulfillment_status === null) {
            return;
        }

        $next = $this->fulfillment_status->next();
        if ($next === null) {
            return;
        }

        $this->fulfillment_status = $next;
        $this->save();
        $this->logChange("Order {$this->reference} → {$next->label()}.");
    }

    /**
     * Resolve and apply the open per-phone customer discount for the given
     * phone number (or clear it when `$phone` is null / unmatched), then
     * recompute totals. Called from the terminal whenever the cart's
     * customer changes. The matched percentage is snapshot onto the order so
     * a later edit of the discount rule can't rewrite a finalised sale.
     */
    public function applyCustomerDiscount(?string $phone): void
    {
        $percent = 0.0;
        $discountId = null;

        if ($phone !== null && $phone !== '') {
            $match = PosCustomerDiscount::findForPhone($phone);

            if ($match !== null) {
                $percent = max(0.0, min(100.0, $match->discount_percent));
                // Remember which row the prepaid wallet belongs to, so the
                // credit is drawn from the right one at checkout.
                $discountId = (int) $match->getKey();
            }
        }

        // Don't move the credit pointer once it's been drawn (a finalised order).
        if (! $this->credit_consumed) {
            $this->customer_discount_id = $discountId;
        }
        $this->customer_discount_percent = round($percent, 2);
        $this->recalculate();
    }

    public function paymentsTotal(): float
    {
        return round((float) $this->payments()->sum('amount'), 2);
    }

    public function registerPayment(PosPaymentMethod $method, float $amount): PosPayment
    {
        $payment = $this->payments()->create([
            'pos_payment_method_id' => $method->id,
            'amount' => round($amount, 2),
            'paid_at' => Carbon::now(),
        ]);

        $this->paid_total = $this->paymentsTotal();
        $this->save();

        return $payment;
    }

    public function isFullyPaid(): bool
    {
        return $this->paymentsTotal() + 0.0001 >= $this->total && $this->total > 0;
    }

    /** Amount still owed on the order (0 once fully paid). */
    public function outstanding(): float
    {
        return max(0.0, round((float) $this->total - $this->paymentsTotal(), 2));
    }

    /** Has the order been paid in full (a zero-total order counts as paid)? */
    public function isPaid(): bool
    {
        return $this->paymentsTotal() + 0.0001 >= (float) $this->total;
    }

    /** Payment state for display: 'paid' | 'partial' | 'unpaid'. */
    public function paymentBadge(): string
    {
        if ($this->isPaid()) {
            return 'paid';
        }

        return $this->paymentsTotal() > 0 ? 'partial' : 'unpaid';
    }

    /**
     * Finalise the order: requires full payment, computes change due.
     */
    public function markPaid(): void
    {
        $paid = $this->paymentsTotal();

        $this->paid_total = $paid;
        $this->change_due = round(max(0.0, $paid - $this->total), 2);
        $this->state = OrderState::Paid;
        $this->ordered_at = Carbon::now();
        $this->save();

        $this->logChange("Order {$this->reference} paid — total {$this->total}, tendered {$paid}.");
    }

    /**
     * Complete the sale atomically: mark paid, post as Done, and decrement
     * raw-material stock for every recipe-backed line — all in one DB
     * transaction so inventory and the order can never drift apart.
     *
     * After the DB state is durably committed, fire {@see PosOrderPaid} so
     * any subscribed automation (e.g. the WhatsApp auto-receipt listener)
     * can act on it. The event is dispatched OUTSIDE the transaction
     * closure — if the commit rolls back, no receipt is sent.
     */
    public function finalizeSale(): void
    {
        DB::transaction(function (): void {
            $this->markPaid();
            $this->state = OrderState::Done;
            // A remote order now enters the delivery queue as "New".
            if ($this->isRemote() && $this->fulfillment_status === null) {
                $this->fulfillment_status = FulfillmentStatus::New;
            }
            $this->consumeCustomerCredit();
            $this->save();
            $this->consumeComponents();
        });

        event(new PosOrderPaid($this));
    }

    /**
     * Confirm a remote order for delivery WITHOUT collecting payment yet
     * (cash on delivery): commit it as Done, consume stock and drop it into
     * the fulfillment queue as "New" — but leave it unpaid. Crucially this does
     * NOT fire {@see PosOrderPaid}, so no cash is booked to the journal until
     * the money is actually collected (see {@see collectPayment()}).
     */
    public function confirmUnpaid(): void
    {
        DB::transaction(function (): void {
            $this->paid_total = $this->paymentsTotal();
            $this->change_due = 0.0;
            $this->state = OrderState::Done;
            $this->ordered_at = Carbon::now();
            if ($this->isRemote() && $this->fulfillment_status === null) {
                $this->fulfillment_status = FulfillmentStatus::New;
            }
            $this->consumeCustomerCredit();
            $this->save();
            $this->consumeComponents();
        });

        $this->logChange("Order {$this->reference} confirmed — pay on delivery (total {$this->total}).");
    }

    /**
     * Collect the money owed on a confirmed-but-unpaid order (the driver
     * returns with the cash): record the outstanding amount against the given
     * method, then fire {@see PosOrderPaid} so the sale is booked to the
     * journal and the receipt goes out — now that payment is real. No-op if the
     * order is already paid.
     */
    public function collectPayment(PosPaymentMethod $method): void
    {
        if ($this->isPaid()) {
            return;
        }

        $outstanding = $this->outstanding();
        DB::transaction(function () use ($method, $outstanding): void {
            if ($outstanding > 0) {
                $this->registerPayment($method, $outstanding);
            }
            $this->paid_total = $this->paymentsTotal();
            $this->change_due = 0.0;
            $this->save();
        });

        $this->logChange("Order {$this->reference} payment collected — {$this->paid_total} via {$method->name}.");
        event(new PosOrderPaid($this));
    }

    /**
     * Static ingredient consumption: for each line, look up the sold
     * product's recipe and subtract `quantity_consumed * order_quantity`
     * from each component's stock. Idempotent — guarded by a flag so a
     * re-validation can never double-consume.
     */
    public function consumeComponents(): void
    {
        if ($this->components_consumed) {
            return;
        }

        $touchedComponents = [];
        /** @var array<int, float> $touchedIngredients id => total qty consumed this sale */
        $touchedIngredients = [];

        foreach ($this->lines()->get() as $line) {
            if ($line->pos_product_id === null) {
                continue;
            }

            $recipe = PosProductRecipe::query()
                ->where('parent_product_id', $line->pos_product_id)
                ->get();

            foreach ($recipe as $row) {
                $decrement = round($row->quantity_consumed * $line->qty, 3);

                if ($decrement <= 0) {
                    continue;
                }

                // A line consumes EITHER a product, a condiment or an ingredient.
                if ($row->component_condiment_id !== null) {
                    PosCondiment::query()
                        ->whereKey($row->component_condiment_id)
                        ->decrement('stock_on_hand', $decrement);

                    continue; // condiments aren't on the Inventory ledger
                }

                if ($row->component_ingredient_id !== null) {
                    PosIngredient::query()
                        ->whereKey($row->component_ingredient_id)
                        ->decrement('stock_on_hand', $decrement);

                    // Ingredients aren't on the product-keyed quant ledger, but
                    // the usage IS posted as a Done audit move below so the
                    // Inventory dashboard reflects the consumption.
                    $id = (int) $row->component_ingredient_id;
                    $touchedIngredients[$id] = round(($touchedIngredients[$id] ?? 0.0) + $decrement, 3);

                    continue;
                }

                if ($row->component_product_id !== null) {
                    PosProduct::query()
                        ->whereKey($row->component_product_id)
                        ->decrement('stock_on_hand', $decrement);

                    $touchedComponents[$row->component_product_id] = true;
                }
            }
        }

        $this->components_consumed = true;
        $this->save();

        // `decrement()` bypasses model events, so the PosProduct::saved
        // Inventory-sync hook never fires for consumed ingredients — mirror
        // their new on-hand onto the Inventory ledger explicitly here so a
        // sale shows as a stock movement on the Inventory dashboard.
        $bridge = app(PosInventoryBridge::class);
        foreach (array_keys($touchedComponents) as $componentId) {
            $fresh = PosProduct::query()->find($componentId);
            if ($fresh !== null) {
                $bridge->sync((int) $fresh->id, (float) $fresh->stock_on_hand, "POS sale {$this->reference}");
            }
        }

        // Ingredient usage → a Done consumption move per ingredient (audit only,
        // never touches the product-keyed quants), so raw-material draw-down is
        // visible on the Inventory dashboard alongside product moves.
        foreach ($touchedIngredients as $ingredientId => $qty) {
            $bridge->recordIngredientConsumption($ingredientId, $qty, "POS sale {$this->reference}");
        }
    }

    public static function irModelDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            model: 'pos.order',
            name: 'POS Order',
            class: self::class,
            table: 'pos_orders',
            module: 'pos',
            fields: [
                new FieldDefinition('reference', 'Reference', 'char', sequence: 10),
                new FieldDefinition('state', 'Status', 'selection', sequence: 20),
                new FieldDefinition('total', 'Total', 'float', sequence: 30),
                new FieldDefinition('paid_total', 'Paid', 'float', sequence: 40),
                new FieldDefinition('ordered_at', 'Ordered', 'datetime', sequence: 50),
                new FieldDefinition('processed_by', 'Processed By', 'char', sequence: 60),
            ],
            views: [
                new ViewDefinition('POS Orders', 'list', [
                    'columns' => [
                        ['field' => 'reference', 'label' => 'Reference', 'sortable' => true],
                        ['field' => 'state', 'label' => 'Status', 'format' => 'badge'],
                        ['field' => 'total', 'label' => 'Total', 'format' => 'money', 'align' => 'right', 'sum' => true, 'sortable' => true],
                        ['field' => 'paid_total', 'label' => 'Paid', 'format' => 'money', 'align' => 'right'],
                        // `processed_by` is an accessor (User name); we can't ORDER BY
                        // it in SQL, so we route sort clicks to the real indexed
                        // `user_id` column via the column's `sort_field` override.
                        // Sorting groups orders by cashier — alphabetic-by-name would
                        // need a USERS join the engine doesn't have yet.
                        ['field' => 'processed_by', 'label' => 'Processed By', 'sortable' => true, 'sort_field' => 'user_id'],
                        ['field' => 'ordered_at', 'label' => 'Ordered', 'format' => 'datetime', 'sortable' => true],
                    ],
                    'default_sort' => [['field' => 'ordered_at', 'dir' => 'desc']],
                    'per_page' => 20,
                    // Date-range presets surfaced as chip buttons above the
                    // table. The footer `total` aggregate respects the active
                    // filter, so the chip row + sum together act as a daily/
                    // weekly/monthly revenue rollup without a dedicated report.
                    'filters' => [
                        ['name' => 'today',      'label' => "Today's Sales", 'field' => 'ordered_at', 'preset' => 'today'],
                        ['name' => 'yesterday',  'label' => 'Yesterday',     'field' => 'ordered_at', 'preset' => 'yesterday'],
                        ['name' => 'this_week',  'label' => 'This Week',     'field' => 'ordered_at', 'preset' => 'this_week'],
                        ['name' => 'this_month', 'label' => 'This Month',    'field' => 'ordered_at', 'preset' => 'this_month'],
                    ],
                    // Opt the list into the "Custom…" range chip — the popover
                    // filters whichever date column we name here. Same field as
                    // the presets so a user mental-model of "Custom = picky
                    // version of Today" stays true.
                    'custom_date_field' => 'ordered_at',
                ]),
                new ViewDefinition('POS Orders', 'kanban', [
                    'group_by' => 'state',
                    // The transient `paid` state (set inside finalizeSale's
                    // DB transaction, immediately followed by `done`) is
                    // intentionally NOT a column — no order ever sits in
                    // it in steady state, and a second "Paid" column next
                    // to the one below would be a confusing duplicate.
                    'stages' => [
                        ['value' => 'draft', 'label' => 'Draft'],
                        ['value' => 'done', 'label' => 'Paid'],
                        ['value' => 'cancelled', 'label' => 'Cancelled'],
                    ],
                    'card' => ['title' => 'reference', 'subtitle' => 'total', 'badges' => ['state']],
                ]),
            ],
        );
    }
}
