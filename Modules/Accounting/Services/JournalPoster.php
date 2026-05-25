<?php

declare(strict_types=1);

namespace Modules\Accounting\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Enums\JournalEntryState;
use Modules\Accounting\Exceptions\UnbalancedJournalEntryException;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Models\JournalItem;

/**
 * The single entry point for creating & posting journal entries.
 *
 * Callers — listeners, manual UI, batch jobs — describe what they want
 * ("a draft entry dated X with these lines"); this service:
 *   1. Auto-numbers the header (via {@see SequenceGenerator}) if no
 *      number was supplied.
 *   2. Persists header + lines in a single DB transaction.
 *   3. On {@see post()}, asserts the double-entry invariant before
 *      flipping the state — out-of-balance throws and the entry stays
 *      `draft` so a human can fix it.
 *
 * Listeners use the high-level {@see record()} which combines create+post
 * for one-shot automated entries (a POS sale, a confirmed purchase bill).
 */
final class JournalPoster
{
    public function __construct(
        private readonly SequenceGenerator $sequences,
    ) {
    }

    /**
     * Build and persist a draft entry.
     *
     * @param  list<array{
     *     account_id: int,
     *     debit?: float,
     *     credit?: float,
     *     partner_id?: int|null,
     *     memo?: string|null
     * }>  $lines
     */
    public function createDraft(
        Carbon $date,
        array $lines,
        ?string $reference = null,
        ?string $narration = null,
        string $prefix = 'MISC',
    ): JournalEntry {
        return DB::transaction(function () use ($date, $lines, $reference, $narration, $prefix): JournalEntry {
            $entry = new JournalEntry([
                'number' => $this->sequences->next($prefix, (int) $date->format('Y')),
                'date' => $date->toDateString(),
                'reference' => $reference,
                'narration' => $narration,
                'state' => JournalEntryState::Draft->value,
                'user_id' => Auth::id(),
            ]);
            $entry->save();

            foreach ($lines as $line) {
                $entry->items()->save(new JournalItem([
                    'account_id' => $line['account_id'],
                    'debit' => round((float) ($line['debit'] ?? 0.0), 2),
                    'credit' => round((float) ($line['credit'] ?? 0.0), 2),
                    'partner_id' => $line['partner_id'] ?? null,
                    'memo' => $line['memo'] ?? null,
                ]));
            }

            return $entry->fresh(['items']) ?? $entry;
        });
    }

    /**
     * Flip an entry from draft to posted. Throws
     * {@see UnbalancedJournalEntryException} (without mutating state) if
     * the lines don't tie out — the caller is expected to surface this
     * as a validation error on the form.
     *
     * Idempotent: posting an already-posted entry is a no-op.
     */
    public function post(JournalEntry $entry): JournalEntry
    {
        if ($entry->isPosted()) {
            return $entry;
        }

        $entry->assertBalanced();

        $entry->state = JournalEntryState::Posted;
        $entry->posted_at = Carbon::now();
        $entry->save();

        $entry->logChange("Entry {$entry->number} posted — total {$entry->totalDebit()}.");

        return $entry;
    }

    /**
     * Create-and-post in one shot — the form used by the automated
     * listeners. If the lines are unbalanced the whole thing rolls back
     * and no draft is left behind (we don't want orphaned drafts cluttering
     * the books on every misconfigured automation).
     *
     * @param  list<array{
     *     account_id: int,
     *     debit?: float,
     *     credit?: float,
     *     partner_id?: int|null,
     *     memo?: string|null
     * }>  $lines
     */
    public function record(
        Carbon $date,
        array $lines,
        ?string $reference = null,
        ?string $narration = null,
        string $prefix = 'MISC',
    ): JournalEntry {
        return DB::transaction(function () use ($date, $lines, $reference, $narration, $prefix): JournalEntry {
            $entry = $this->createDraft($date, $lines, $reference, $narration, $prefix);
            return $this->post($entry);
        });
    }
}
