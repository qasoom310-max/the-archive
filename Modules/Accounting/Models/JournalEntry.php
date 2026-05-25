<?php

declare(strict_types=1);

namespace Modules\Accounting\Models;

use App\Erp\Chatter\Chatterable;
use App\Erp\Chatter\HasChatter;
use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Registry\FieldDefinition;
use App\Erp\Registry\ModelDefinition;
use App\Erp\Registry\ViewDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Accounting\Enums\JournalEntryState;
use Modules\Accounting\Exceptions\UnbalancedJournalEntryException;

/**
 * Header of a double-entry journal entry. Mutates freely while in `draft`;
 * locks at `posted` and feeds every balance/report from that point on.
 *
 * The double-entry invariant — sum(debits) === sum(credits) across all
 * child {@see JournalItem}s — is enforced at posting time by
 * {@see \Modules\Accounting\Services\JournalPoster::post()}, NOT on every
 * `save()` (a partially-built draft is allowed to be unbalanced — that's
 * normal mid-edit). {@see assertBalanced()} is the explicit check.
 *
 * @property int $id
 * @property string $number
 * @property Carbon $date
 * @property string|null $reference
 * @property string|null $narration
 * @property JournalEntryState $state
 * @property int|null $user_id
 * @property Carbon|null $posted_at
 */
final class JournalEntry extends Model implements Chatterable, DefinesIrModel
{
    use HasChatter;

    protected $table = 'journal_entries';

    /** @var list<string> */
    protected $fillable = [
        'number', 'date', 'reference', 'narration', 'state', 'user_id', 'posted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'state' => JournalEntryState::class,
            'posted_at' => 'datetime',
            'user_id' => 'integer',
        ];
    }

    /**
     * @return HasMany<JournalItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(JournalItem::class, 'journal_entry_id');
    }

    public function totalDebit(): float
    {
        return round((float) $this->items()->sum('debit'), 2);
    }

    public function totalCredit(): float
    {
        return round((float) $this->items()->sum('credit'), 2);
    }

    public function isBalanced(): bool
    {
        return abs($this->totalDebit() - $this->totalCredit()) < 0.005;
    }

    public function isPosted(): bool
    {
        return $this->state === JournalEntryState::Posted;
    }

    /**
     * Throw {@see UnbalancedJournalEntryException} if the lines don't tie out.
     * Called by {@see \Modules\Accounting\Services\JournalPoster::post()}
     * but also exposed publicly so a Livewire form can preview the same
     * error the user would hit on Post.
     */
    public function assertBalanced(): void
    {
        $debit = $this->totalDebit();
        $credit = $this->totalCredit();

        if (abs($debit - $credit) >= 0.005) {
            throw new UnbalancedJournalEntryException($debit, $credit);
        }
    }

    public static function irModelDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            model: 'accounting.journal_entry',
            name: 'Journal Entry',
            class: self::class,
            table: 'journal_entries',
            module: 'accounting',
            fields: [
                new FieldDefinition('number', 'Number', 'char', sequence: 10),
                new FieldDefinition('date', 'Date', 'date', required: true, sequence: 20),
                new FieldDefinition('reference', 'Reference', 'char', sequence: 30),
                new FieldDefinition('state', 'Status', 'selection', sequence: 40),
                new FieldDefinition('narration', 'Narration', 'text', sequence: 50),
            ],
            views: [
                new ViewDefinition('Journal Entries', 'list', [
                    'columns' => [
                        ['field' => 'number', 'label' => 'Number', 'sortable' => true],
                        ['field' => 'date', 'label' => 'Date', 'format' => 'date', 'sortable' => true],
                        ['field' => 'reference', 'label' => 'Reference'],
                        ['field' => 'state', 'label' => 'Status', 'format' => 'badge'],
                    ],
                    'default_sort' => [['field' => 'date', 'dir' => 'desc']],
                    'per_page' => 20,
                    'open' => '/app/accounting/journal_entry/{id}',
                    'searchable' => ['number', 'reference'],
                    'filters' => [
                        ['name' => 'today',      'label' => "Today",      'field' => 'date', 'preset' => 'today'],
                        ['name' => 'this_week',  'label' => 'This Week',  'field' => 'date', 'preset' => 'this_week'],
                        ['name' => 'this_month', 'label' => 'This Month', 'field' => 'date', 'preset' => 'this_month'],
                    ],
                    'custom_date_field' => 'date',
                ]),
                new ViewDefinition('Journal Entry', 'form', [
                    'cols' => 2,
                    'fields' => [
                        ['field' => 'number', 'label' => 'Number', 'widget' => 'text', 'placeholder' => 'Auto-generated on save'],
                        ['field' => 'date', 'label' => 'Date', 'widget' => 'date', 'required' => true],
                        ['field' => 'reference', 'label' => 'Reference', 'widget' => 'text'],
                        ['field' => 'narration', 'label' => 'Narration', 'widget' => 'textarea'],
                    ],
                ]),
            ],
        );
    }
}
