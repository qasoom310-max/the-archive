<?php

declare(strict_types=1);

namespace Modules\Limousine\Models;

use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Registry\FieldDefinition;
use App\Erp\Registry\ModelDefinition;
use App\Erp\Registry\ViewDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Money handed to a driver from the petty-cash float, and its whole life:
 * issued → confirmed → cleared.
 *
 * Settlement is where the arithmetic the office used to do by hand happens:
 * receipts under the amount → the difference is a SALARY DEDUCTION; receipts
 * over it → the difference is REIMBURSED from the float (the standing rule
 * here, chosen over asking every time). Both are snapshotted, because what was
 * decided about a man's pay must not move when a line is edited later.
 *
 * @property int $id
 * @property string|null $reference
 * @property int $driver_id
 * @property Carbon|null $date
 * @property float $amount
 * @property string $status
 * @property string|null $issued_by
 * @property Carbon|null $confirmed_at
 * @property string|null $confirmed_by
 * @property Carbon|null $settled_at
 * @property string|null $settled_by
 * @property float $receipts_total
 * @property float $shortfall
 * @property float $excess
 * @property string|null $notes
 * @property-read LimoDriver|null $driver
 * @property-read \Illuminate\Database\Eloquent\Collection<int, LimoPettyLine> $lines
 */
final class LimoPettyAdvance extends Model implements DefinesIrModel
{
    use \App\Models\Concerns\HasReference;

    protected $table = 'limo_petty_advances';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CLEARED = 'cleared';

    /** @var list<string> */
    protected $fillable = [
        'reference', 'driver_id', 'date', 'amount', 'status', 'issued_by',
        'confirmed_at', 'confirmed_by', 'settled_at', 'settled_by',
        'receipts_total', 'shortfall', 'excess', 'notes',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'amount' => 0, 'status' => self::STATUS_ISSUED,
        'receipts_total' => 0, 'shortfall' => 0, 'excess' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'driver_id' => 'integer',
            'date' => 'date',
            'amount' => 'float',
            'confirmed_at' => 'datetime',
            'settled_at' => 'datetime',
            'receipts_total' => 'float',
            'shortfall' => 'float',
            'excess' => 'float',
        ];
    }

    public function referencePrefix(): string
    {
        return 'PC';
    }

    /**
     * @return BelongsTo<LimoDriver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(LimoDriver::class, 'driver_id');
    }

    /**
     * @return HasMany<LimoPettyLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(LimoPettyLine::class, 'advance_id');
    }

    public function isCleared(): bool
    {
        return $this->status === self::STATUS_CLEARED;
    }

    /** What the paper receipts add up to right now (live until settlement). */
    public function linesTotal(): float
    {
        return round((float) $this->lines()->sum('amount'), 3);
    }

    public static function irModelDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            model: 'limousine.petty_cash',
            name: 'Petty Cash',
            class: self::class,
            table: 'limo_petty_advances',
            fields: [
                new FieldDefinition('reference', 'Reference', 'char', sequence: 10),
                new FieldDefinition('date', 'Date', 'date', sequence: 20),
                new FieldDefinition('amount', 'Amount', 'float', sequence: 30),
                new FieldDefinition('status', 'Status', 'selection', selection: [
                    ['value' => self::STATUS_ISSUED, 'label' => 'Issued'],
                    ['value' => self::STATUS_CONFIRMED, 'label' => 'Confirmed'],
                    ['value' => self::STATUS_CLEARED, 'label' => 'Cleared'],
                ], sequence: 40),
            ],
            views: [
                new ViewDefinition('Petty Cash', 'list', [
                    'columns' => [
                        ['field' => 'reference', 'label' => 'Reference', 'sortable' => true],
                        ['field' => 'date', 'label' => 'Date', 'format' => 'date', 'sortable' => true],
                        ['field' => 'amount', 'label' => 'Amount', 'format' => 'money', 'align' => 'right'],
                        ['field' => 'status', 'label' => 'Status', 'format' => 'badge'],
                    ],
                    'default_sort' => [['field' => 'id', 'dir' => 'desc']],
                    'per_page' => 20,
                ]),
            ],
        );
    }
}
