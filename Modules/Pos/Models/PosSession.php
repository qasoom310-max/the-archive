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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\SessionState;

/**
 * @property int $id
 * @property string $reference
 * @property int|null $user_id
 * @property SessionState $state
 * @property float $opening_cash
 * @property float|null $closing_cash
 * @property float|null $expected_cash
 * @property float|null $cash_difference
 * @property string|null $note
 * @property Carbon|null $opened_at
 * @property Carbon|null $closed_at
 */
final class PosSession extends Model implements Chatterable, DefinesIrModel
{
    use HasChatter;

    protected $table = 'pos_sessions';

    /** @var list<string> */
    protected $fillable = [
        'reference', 'user_id', 'state', 'opening_cash', 'closing_cash',
        'expected_cash', 'cash_difference', 'note', 'opened_at', 'closed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => SessionState::class,
            'opening_cash' => 'float',
            'closing_cash' => 'float',
            'expected_cash' => 'float',
            'cash_difference' => 'float',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany<PosOrder, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(PosOrder::class, 'pos_session_id');
    }

    /**
     * Presence rows for the single shared register (heartbeat).
     *
     * @return HasMany<PosSessionParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(PosSessionParticipant::class, 'pos_session_id');
    }

    public function isOpen(): bool
    {
        return $this->state === SessionState::Opened;
    }

    /** Net sales of finalised orders in this session. */
    public function salesTotal(): float
    {
        return round((float) $this->orders()
            ->whereIn('state', [OrderState::Paid->value, OrderState::Done->value])
            ->sum('total'), 2);
    }

    /** Cash collected through cash payment methods this session. */
    public function cashCollected(): float
    {
        return round((float) PosPayment::query()
            ->whereHas('order', fn (Builder $q): Builder => $q
                ->where('pos_session_id', $this->id)
                ->whereIn('state', [OrderState::Paid->value, OrderState::Done->value]))
            ->whereHas('method', fn (Builder $q): Builder => $q->where('is_cash', true))
            ->sum('amount'), 2);
    }

    public function expectedCash(): float
    {
        return round($this->opening_cash + $this->cashCollected(), 2);
    }

    /**
     * Close the session and reconcile the cash drawer.
     */
    public function close(float $countedCash): void
    {
        $expected = $this->expectedCash();

        $this->closing_cash = round($countedCash, 2);
        $this->expected_cash = $expected;
        $this->cash_difference = round($countedCash - $expected, 2);
        $this->state = SessionState::Closed;
        $this->closed_at = Carbon::now();
        $this->save();

        $this->logChange(
            "Session {$this->reference} closed — expected {$expected}, counted "
            . "{$this->closing_cash}, difference {$this->cash_difference}.",
        );
    }

    public static function irModelDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            model: 'pos.session',
            name: 'POS Session',
            class: self::class,
            table: 'pos_sessions',
            module: 'pos',
            fields: [
                new FieldDefinition('reference', 'Reference', 'char', sequence: 10),
                new FieldDefinition('state', 'Status', 'selection', sequence: 20),
                new FieldDefinition('opening_cash', 'Opening Cash', 'float', sequence: 30),
                new FieldDefinition('expected_cash', 'Expected Cash', 'float', sequence: 40),
                new FieldDefinition('opened_at', 'Opened', 'datetime', sequence: 50),
            ],
            views: [
                new ViewDefinition('POS Sessions', 'list', [
                    'columns' => [
                        ['field' => 'reference', 'label' => 'Reference', 'sortable' => true],
                        ['field' => 'state', 'label' => 'Status', 'format' => 'badge'],
                        ['field' => 'opening_cash', 'label' => 'Opening', 'format' => 'number', 'align' => 'right'],
                        ['field' => 'expected_cash', 'label' => 'Expected', 'format' => 'number', 'align' => 'right'],
                        ['field' => 'opened_at', 'label' => 'Opened', 'format' => 'datetime', 'sortable' => true],
                    ],
                    'default_sort' => [['field' => 'opened_at', 'dir' => 'desc']],
                    'per_page' => 20,
                    'open' => '/app/pos/session/{id}',
                ]),
                new ViewDefinition('POS Sessions', 'kanban', [
                    'group_by' => 'state',
                    'stages' => [
                        ['value' => 'opened', 'label' => 'In progress'],
                        ['value' => 'closed', 'label' => 'Closed'],
                    ],
                    'card' => ['title' => 'reference', 'subtitle' => 'opening_cash', 'badges' => ['state']],
                    'open' => '/app/pos/session/{id}',
                ]),
            ],
        );
    }
}
