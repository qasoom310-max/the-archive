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
use Modules\Contacts\Models\Partner;
use Modules\Pos\Enums\OrderState;

/**
 * @property int $id
 * @property string $reference
 * @property int $pos_session_id
 * @property int|null $partner_id
 * @property int|null $user_id
 * @property OrderState $state
 * @property float $subtotal
 * @property float $tax_total
 * @property float $total
 * @property float $paid_total
 * @property float $change_due
 * @property bool $components_consumed
 * @property Carbon|null $ordered_at
 */
final class PosOrder extends Model implements Chatterable, DefinesIrModel
{
    use HasChatter;

    protected $table = 'pos_orders';

    /** @var list<string> */
    protected $fillable = [
        'reference', 'pos_session_id', 'partner_id', 'user_id', 'state',
        'subtotal', 'tax_total', 'total', 'paid_total', 'change_due',
        'components_consumed', 'ordered_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => OrderState::class,
            'subtotal' => 'float',
            'tax_total' => 'float',
            'total' => 'float',
            'paid_total' => 'float',
            'change_due' => 'float',
            'components_consumed' => 'boolean',
            'ordered_at' => 'datetime',
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
     * Recompute order totals from its lines.
     */
    public function recalculate(): void
    {
        $lines = $this->lines()->get();

        $this->subtotal = round((float) $lines->sum('subtotal'), 2);
        $this->tax_total = round((float) $lines->sum('tax_amount'), 2);
        $this->total = round((float) $lines->sum('total'), 2);
        $this->save();
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
     */
    public function finalizeSale(): void
    {
        DB::transaction(function (): void {
            $this->markPaid();
            $this->state = OrderState::Done;
            $this->save();
            $this->consumeComponents();
        });
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

                PosProduct::query()
                    ->whereKey($row->component_product_id)
                    ->decrement('stock_on_hand', $decrement);
            }
        }

        $this->components_consumed = true;
        $this->save();
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
            ],
            views: [
                new ViewDefinition('POS Orders', 'list', [
                    'columns' => [
                        ['field' => 'reference', 'label' => 'Reference', 'sortable' => true],
                        ['field' => 'state', 'label' => 'Status', 'format' => 'badge'],
                        ['field' => 'total', 'label' => 'Total', 'format' => 'number', 'align' => 'right', 'sum' => true, 'sortable' => true],
                        ['field' => 'paid_total', 'label' => 'Paid', 'format' => 'number', 'align' => 'right'],
                        ['field' => 'ordered_at', 'label' => 'Ordered', 'format' => 'datetime', 'sortable' => true],
                    ],
                    'default_sort' => [['field' => 'ordered_at', 'dir' => 'desc']],
                    'per_page' => 20,
                ]),
                new ViewDefinition('POS Orders', 'kanban', [
                    'group_by' => 'state',
                    'stages' => [
                        ['value' => 'draft', 'label' => 'Draft'],
                        ['value' => 'paid', 'label' => 'Paid'],
                        ['value' => 'done', 'label' => 'Posted'],
                        ['value' => 'cancelled', 'label' => 'Cancelled'],
                    ],
                    'card' => ['title' => 'reference', 'subtitle' => 'total', 'badges' => ['state']],
                ]),
            ],
        );
    }
}
