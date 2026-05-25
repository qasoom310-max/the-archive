<?php

declare(strict_types=1);

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One side of a journal entry — either a debit or a credit on a specific
 * account. The double-entry invariant lives on the parent {@see JournalEntry},
 * not here: a single line can carry any nonzero amount; balance is checked
 * across the full set of lines at posting time.
 *
 * Convention: a line never carries both a debit and a credit. Setters
 * normalise this — calling {@see asDebit()} clears any prior credit and
 * vice versa.
 *
 * @property int $id
 * @property int $journal_entry_id
 * @property int $account_id
 * @property float $debit
 * @property float $credit
 * @property int|null $partner_id   Logical ref to a contacts.partner — NO FK
 * @property string|null $memo
 */
final class JournalItem extends Model
{
    protected $table = 'journal_items';

    /** @var list<string> */
    protected $fillable = [
        'journal_entry_id', 'account_id', 'debit', 'credit', 'partner_id', 'memo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'debit' => 'float',
            'credit' => 'float',
            'partner_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    /**
     * Builder helper — set this line as a debit of `$amount` and zero out
     * the credit side. Rounds to the project's 2-decimal money policy.
     */
    public function asDebit(float $amount): self
    {
        $this->debit = round($amount, 2);
        $this->credit = 0.0;
        return $this;
    }

    public function asCredit(float $amount): self
    {
        $this->credit = round($amount, 2);
        $this->debit = 0.0;
        return $this;
    }

    /**
     * +debit for a normal-debit account / +credit for a normal-credit
     * account is positive; the opposite is negative. Convenience for
     * ledger views.
     */
    public function signedAmount(): float
    {
        return round($this->debit - $this->credit, 2);
    }
}
