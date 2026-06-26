<?php

declare(strict_types=1);

namespace Modules\Pos\Models;

use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Registry\FieldDefinition;
use App\Erp\Registry\ModelDefinition;
use App\Erp\Registry\ViewDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Modules\Pos\Services\PosInventoryBridge;

/**
 * A damage / waste write-off: a quantity of one catalogue item (product,
 * ingredient or condiment) lost to breakage, spoilage, spillage, expiry or
 * theft. Logging an entry decrements that item's `stock_on_hand` and snapshots
 * its unit cost so the Damage Report can value the loss (`loss_value =
 * quantity × unit_cost`). Deleting an entry restores the stock — entries are a
 * log, not editable in place, so the stock effect is always create/delete only.
 *
 * Condiments carry no tracked cost, so their entries record quantity with a
 * zero loss value (the report shows the units lost without a money figure).
 *
 * @property int $id
 * @property string|null $reference
 * @property \Illuminate\Support\Carbon $damaged_on
 * @property string $item_type  product|ingredient|condiment
 * @property int|null $pos_product_id
 * @property int|null $pos_ingredient_id
 * @property int|null $pos_condiment_id
 * @property float $quantity
 * @property float $unit_cost   Snapshot at time of damage
 * @property float $loss_value  quantity × unit_cost
 * @property string $reason
 * @property string|null $note
 * @property string|null $recorded_by
 */
final class PosDamage extends Model implements DefinesIrModel
{
    use \App\Models\Concerns\HasReference;

    protected $table = 'pos_damages';

    /** @var list<string> */
    protected $fillable = [
        'reference', 'damaged_on', 'item_type',
        'pos_product_id', 'pos_ingredient_id', 'pos_condiment_id',
        'quantity', 'unit_cost', 'loss_value', 'reason', 'note', 'recorded_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'item_type' => 'product',
        'quantity' => 0,
        'unit_cost' => 0,
        'loss_value' => 0,
        'reason' => 'other',
    ];

    /**
     * The three catalogues an item can be damaged from. Drives the form's
     * item-type pills and validates the stored `item_type`.
     *
     * @var list<string>
     */
    public const ITEM_TYPES = ['product', 'ingredient', 'condiment'];

    /**
     * Why the stock was written off. value => human label (run through __()).
     *
     * @var array<string, string>
     */
    public const REASONS = [
        'broken' => 'Broken / damaged',
        'expired' => 'Expired',
        'spoiled' => 'Spoiled',
        'spill' => 'Spilled / wasted',
        'theft' => 'Theft / loss',
        'other' => 'Other',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'damaged_on' => 'date',
            'pos_product_id' => 'integer',
            'pos_ingredient_id' => 'integer',
            'pos_condiment_id' => 'integer',
            'quantity' => 'float',
            'unit_cost' => 'float',
            'loss_value' => 'float',
        ];
    }

    public function referencePrefix(): string
    {
        return 'DMG';
    }

    protected static function booted(): void
    {
        static::creating(function (PosDamage $damage): void {
            $damage->loss_value = round((float) $damage->quantity * (float) $damage->unit_cost, 3);

            if (($damage->recorded_by === null || $damage->recorded_by === '') && Auth::check()) {
                $damage->recorded_by = (string) Auth::user()?->getAttribute('name');
            }
        });

        static::created(function (PosDamage $damage): void {
            // Remove the damaged quantity from stock now the row (and its
            // reference, used as the ledger move label) exists. The reference is
            // set first by the HasReference trait's created hook.
            $damage->adjustStock(-1.0);
        });

        // Deleting an entry puts the stock back — keeps on-hand honest if a
        // mistaken write-off is removed.
        static::deleting(function (PosDamage $damage): void {
            $damage->adjustStock(1.0);
        });
    }

    /**
     * Apply a stock delta for this entry to whichever catalogue item it points
     * at. `$sign` is -1 when logging the damage (remove stock) and +1 when the
     * entry is deleted (restore it). Products go through `save()` so the
     * PosProduct→Inventory ledger sync hook fires; ingredient removals also post
     * an audit consumption move. Missing items (since deleted) are a no-op.
     */
    private function adjustStock(float $sign): void
    {
        $delta = $sign * (float) $this->quantity;
        if ($delta === 0.0) {
            return;
        }

        switch ($this->item_type) {
            case 'product':
                $product = PosProduct::query()->find($this->pos_product_id);
                if ($product !== null) {
                    $product->stock_on_hand = (float) $product->stock_on_hand + $delta;
                    $product->save(); // saved hook mirrors the new on-hand to the Inventory ledger
                }
                break;

            case 'ingredient':
                $ingredient = PosIngredient::query()->find($this->pos_ingredient_id);
                if ($ingredient !== null) {
                    $ingredient->stock_on_hand = (float) $ingredient->stock_on_hand + $delta;
                    $ingredient->save();

                    // Audit move on the Inventory dashboard for the draw-down only.
                    if ($delta < 0) {
                        app(PosInventoryBridge::class)->recordIngredientConsumption(
                            (int) $ingredient->id,
                            abs($delta),
                            'Damage ' . ($this->reference ?? ''),
                        );
                    }
                }
                break;

            case 'condiment':
                $condiment = PosCondiment::query()->find($this->pos_condiment_id);
                if ($condiment !== null) {
                    $condiment->stock_on_hand = (float) $condiment->stock_on_hand + $delta;
                    $condiment->save();
                }
                break;
        }
    }

    /**
     * @return BelongsTo<PosProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(PosProduct::class, 'pos_product_id');
    }

    /**
     * @return BelongsTo<PosIngredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(PosIngredient::class, 'pos_ingredient_id');
    }

    /**
     * @return BelongsTo<PosCondiment, $this>
     */
    public function condiment(): BelongsTo
    {
        return $this->belongsTo(PosCondiment::class, 'pos_condiment_id');
    }

    /** Localised name of the damaged item (for the report + list). */
    public function getItemLabelAttribute(): string
    {
        $item = match ($this->item_type) {
            'product' => $this->product,
            'ingredient' => $this->ingredient,
            'condiment' => $this->condiment,
            default => null,
        };

        $name = $item?->getAttribute('name');

        return is_string($name) && $name !== '' ? $name : __('(deleted item)');
    }

    /** Translated label for the write-off reason. */
    public function getReasonLabelAttribute(): string
    {
        return __(self::REASONS[$this->reason] ?? self::REASONS['other']);
    }

    public static function irModelDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            model: 'pos.damage',
            name: 'Damage',
            class: self::class,
            table: 'pos_damages',
            module: 'pos',
            fields: [
                new FieldDefinition('reference', 'Reference', 'char', readonly: true, sequence: 5),
                new FieldDefinition('damaged_on', 'Date', 'date', required: true, sequence: 10),
                new FieldDefinition('item_type', 'Item type', 'selection', sequence: 15),
                new FieldDefinition('quantity', 'Quantity', 'float', required: true, sequence: 20),
                new FieldDefinition('unit_cost', 'Unit cost', 'float', sequence: 25),
                new FieldDefinition('loss_value', 'Loss value', 'float', readonly: true, sequence: 30),
                new FieldDefinition('reason', 'Reason', 'selection', sequence: 35),
                new FieldDefinition('note', 'Note', 'text', sequence: 40),
            ],
            views: [
                // Metadata + ACL only — the route /app/pos/damage renders the
                // bespoke Damage Report page (date filter + loss totals), not
                // the generic engine list.
                new ViewDefinition('Damage', 'list', [
                    'columns' => [
                        ['field' => 'reference', 'label' => 'Reference'],
                        ['field' => 'damaged_on', 'label' => 'Date', 'format' => 'date', 'sortable' => true],
                        ['field' => 'item_label', 'label' => 'Item'],
                        ['field' => 'quantity', 'label' => 'Qty', 'format' => 'number', 'align' => 'right'],
                        ['field' => 'loss_value', 'label' => 'Loss', 'format' => 'money', 'align' => 'right', 'sortable' => true],
                        ['field' => 'reason_label', 'label' => 'Reason'],
                    ],
                    'default_sort' => [['field' => 'damaged_on', 'dir' => 'desc']],
                    'per_page' => 20,
                    'open' => '/app/pos/damage',
                ]),
            ],
        );
    }
}
