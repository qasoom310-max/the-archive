<?php

declare(strict_types=1);

namespace Modules\Accounting\Models;

use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Registry\FieldDefinition;
use App\Erp\Registry\ModelDefinition;
use App\Erp\Registry\ViewDefinition;
use App\Erp\Translation\TranslatableModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Accounting\Enums\AccountType;
use Spatie\Translatable\HasTranslations;

/**
 * A node in the Chart of Accounts.
 *
 * Hierarchy: a parent account is a *grouping* (e.g. "Current Assets") with
 * no movements of its own — children carry the journal items, parents
 * roll up. `balance()` and `signedBalance()` operate on a single account;
 * `subtreeBalance()` rolls up children.
 *
 * @property int $id
 * @property string $code
 * @property string $name      Translatable. Stored as JSON `{"en":..., "ar":...}`;
 *                             read returns the active-locale value
 *                             (`app()->getLocale()`, driven by `company.language`
 *                             via `SetLocale` middleware).
 * @property AccountType $type
 * @property int|null $parent_id
 * @property bool $is_reconcilable
 * @property bool $active
 */
final class Account extends Model implements DefinesIrModel, TranslatableModel
{
    use HasTranslations;

    protected $table = 'accounts';

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'type', 'parent_id', 'is_reconcilable', 'active'];

    /** @var list<string> */
    public array $translatable = ['name'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'is_reconcilable' => 'boolean',
            'active' => 'boolean',
            'parent_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Account, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    /**
     * @return HasMany<JournalItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(JournalItem::class, 'account_id');
    }

    /**
     * Look an account up by its stable COA code — used by the automated
     * posting listeners to resolve mapping config keys (e.g. "1010" →
     * the Cash account) without depending on database ids.
     */
    public static function byCode(string $code): ?self
    {
        /** @var Account|null */
        return self::query()->where('code', $code)->first();
    }

    /**
     * Total debits POSTED to this account (drafts are ignored — they
     * don't yet exist in the books). Optionally constrained to a date
     * window via the parent entry's `date` column.
     *
     * `$asOf` (inclusive) is convenient for balance-sheet "as of" queries;
     * `$from` is convenient for income-statement "for the period" queries.
     */
    public function totalDebit(?Carbon $from = null, ?Carbon $asOf = null): float
    {
        return (float) $this->postedItemsQuery($from, $asOf)->sum('journal_items.debit');
    }

    public function totalCredit(?Carbon $from = null, ?Carbon $asOf = null): float
    {
        return (float) $this->postedItemsQuery($from, $asOf)->sum('journal_items.credit');
    }

    /**
     * Raw (debit - credit) balance — useful when you specifically want
     * the unsigned ledger view. For statements you usually want
     * {@see signedBalance()} which respects the account's normal-balance
     * convention.
     */
    public function balance(?Carbon $from = null, ?Carbon $asOf = null): float
    {
        return round($this->totalDebit($from, $asOf) - $this->totalCredit($from, $asOf), 2);
    }

    /**
     * Balance expressed as "more of what this account naturally holds".
     * For an asset/expense account that's `debit - credit`; for a
     * liability/equity/income account it's `credit - debit`. This is
     * the value that goes on financial statements.
     */
    public function signedBalance(?Carbon $from = null, ?Carbon $asOf = null): float
    {
        return $this->type->signedBalance(
            $this->totalDebit($from, $asOf),
            $this->totalCredit($from, $asOf),
        );
    }

    /**
     * Rollup balance: this account's signed balance PLUS every descendant's,
     * recursively. Lets a parent grouping account (e.g. "Current Assets")
     * surface a meaningful total in tree-style reports.
     *
     * Iterative + visited-set so malformed parent links never infinite-loop
     * (same pattern as PosCategory::subtreeIds).
     */
    public function subtreeBalance(?Carbon $from = null, ?Carbon $asOf = null): float
    {
        $ids = $this->subtreeIds();

        if ($ids === []) {
            return 0.0;
        }

        $debit = (float) JournalItem::query()
            ->whereIn('account_id', $ids)
            ->whereHas('entry', $this->postedEntryFilter($from, $asOf))
            ->sum('debit');

        $credit = (float) JournalItem::query()
            ->whereIn('account_id', $ids)
            ->whereHas('entry', $this->postedEntryFilter($from, $asOf))
            ->sum('credit');

        return $this->type->signedBalance($debit, $credit);
    }

    /**
     * @return list<int>
     */
    public function subtreeIds(): array
    {
        if ($this->id === null) {
            return [];
        }

        /** @var array<int, list<int>> $childrenOf */
        $childrenOf = [];
        foreach (self::query()->get(['id', 'parent_id']) as $row) {
            $childrenOf[$row->parent_id ?? 0][] = (int) $row->id;
        }

        $ids = [];
        $stack = [(int) $this->id];
        $seen = [];

        while ($stack !== []) {
            $current = array_pop($stack);

            if ($current === null || isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;
            $ids[] = $current;

            foreach ($childrenOf[$current] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return $ids;
    }

    /**
     * Shared join: posted items only, optional date window on the parent
     * entry's `date`. Returned as a Builder so callers can stack `sum()`
     * / `count()` etc. without re-deriving the join.
     *
     * @return Builder<JournalItem>
     */
    private function postedItemsQuery(?Carbon $from, ?Carbon $asOf): Builder
    {
        return JournalItem::query()
            ->where('account_id', $this->id)
            ->whereHas('entry', $this->postedEntryFilter($from, $asOf));
    }

    /**
     * @return \Closure(Builder<JournalEntry>): void
     */
    private function postedEntryFilter(?Carbon $from, ?Carbon $asOf): \Closure
    {
        return function (Builder $q) use ($from, $asOf): void {
            $q->where('state', 'posted');

            if ($from !== null) {
                $q->where('date', '>=', $from->toDateString());
            }

            if ($asOf !== null) {
                $q->where('date', '<=', $asOf->toDateString());
            }
        };
    }

    public static function irModelDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            model: 'accounting.account',
            name: 'Account',
            class: self::class,
            table: 'accounts',
            module: 'accounting',
            fields: [
                new FieldDefinition('code', 'Code', 'char', required: true, sequence: 10),
                new FieldDefinition('name', 'Name', 'char', required: true, sequence: 20),
                new FieldDefinition('type', 'Type', 'selection', required: true, sequence: 30),
                new FieldDefinition('parent_id', 'Parent', 'many2one', relation: 'accounting.account', sequence: 40),
                new FieldDefinition('is_reconcilable', 'Reconcilable', 'boolean', sequence: 50),
                new FieldDefinition('active', 'Active', 'boolean', sequence: 60),
            ],
            views: [
                new ViewDefinition('Chart of Accounts', 'list', [
                    'columns' => [
                        ['field' => 'code', 'label' => 'Code', 'sortable' => true],
                        ['field' => 'name', 'label' => 'Name', 'sortable' => true],
                        ['field' => 'type', 'label' => 'Type', 'format' => 'badge'],
                        ['field' => 'is_reconcilable', 'label' => 'Reconcilable', 'format' => 'toggle'],
                        ['field' => 'active', 'label' => 'Active', 'format' => 'toggle'],
                    ],
                    'default_sort' => [['field' => 'code', 'dir' => 'asc']],
                    'per_page' => 50,
                    'open' => '/app/accounting/account/{id}',
                    'searchable' => ['code', 'name'],
                ]),
                new ViewDefinition('Account', 'form', [
                    'cols' => 2,
                    'fields' => [
                        ['field' => 'code', 'label' => 'Code', 'widget' => 'text', 'required' => true],
                        ['field' => 'name', 'label' => 'Name', 'widget' => 'text', 'required' => true, 'translatable' => true],
                        [
                            'field' => 'type',
                            'label' => 'Type',
                            'widget' => 'select',
                            'required' => true,
                            'options' => [
                                ['value' => 'asset', 'label' => 'Asset'],
                                ['value' => 'liability', 'label' => 'Liability'],
                                ['value' => 'equity', 'label' => 'Equity'],
                                ['value' => 'income', 'label' => 'Income'],
                                ['value' => 'expense', 'label' => 'Expense'],
                            ],
                        ],
                        [
                            'field' => 'parent_id',
                            'label' => 'Parent account',
                            'widget' => 'select',
                            'optionsFrom' => [
                                'model' => self::class,
                                'value' => 'id',
                                'label' => 'name',
                                'orderBy' => 'code',
                                'excludeSelf' => true,
                            ],
                            'help' => 'Optional — leave blank for a top-level account.',
                        ],
                        ['field' => 'is_reconcilable', 'label' => 'Reconcilable', 'widget' => 'checkbox'],
                        ['field' => 'active', 'label' => 'Active', 'widget' => 'checkbox'],
                    ],
                ]),
            ],
        );
    }
}
