<?php

declare(strict_types=1);

namespace Modules\Limousine\Console;

use App\Erp\Tenancy\WorkspaceManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoReceipt;
use Modules\Limousine\Support\DuplicateTripGroups;
use Throwable;

/**
 * Read-only. The detailed twin of {@see FindDuplicateTrips} — same groups,
 * same "accidental-looking vs legitimate multi-vehicle" classification —
 * but for the accidental-looking groups only, this additionally checks
 * whether EITHER row already has money recorded against it (an invoice or a
 * receipt), because a group where money is already attached on one side is
 * almost certainly two genuinely separate, separately-billed trips, not one
 * booking entered twice.
 *
 * NOTHING here is a confirmed defect list — only a shortlist for a human who
 * knows the business to look at before anything is deleted.
 * {@see \Modules\Limousine\Support\LegacyBookingImporter}'s own TWIN guard
 * deliberately never compares two already-imported legacy bookings against
 * each other (its own doc comment: "two old-system bookings carry two
 * numbers and are two trips"), so the "accidental-looking" bucket includes
 * pairs the import process itself was designed to treat as real. This
 * command's only job is to narrow that bucket by financial risk, not to
 * decide which rows are genuine mistakes.
 *
 * Writes nothing, ever — no booking, invoice or receipt is read for
 * anything other than reporting.
 */
final class ReviewDuplicateTrips extends Command
{
    protected $signature = 'limo:review-duplicate-trips {--workspace= : Only this workspace id}';

    protected $description = 'A detailed, money-aware shortlist of the accidental-looking duplicate trips. Read-only — changes and decides nothing.';

    public function handle(WorkspaceManager $workspaces, DuplicateTripGroups $finder): int
    {
        $only = $this->option('workspace');

        foreach ($workspaces->all() as $workspace) {
            if ($only !== null && (string) $workspace->id !== (string) $only) {
                continue;
            }

            $this->line('');
            $this->info($workspace->name . ':');

            try {
                if ($workspace->is_main) {
                    $this->reportHere($finder);

                    continue;
                }

                $path = $workspace->databasePath();

                if ($path === null || ! is_file($path)) {
                    $this->warn('  skipped: its database file is missing.');

                    continue;
                }

                $workspaces->withTenant($path, function () use ($finder): void {
                    $this->reportHere($finder);
                });
            } catch (Throwable $e) {
                // One workspace failing must never stop the rest.
                $this->warn('  skipped: ' . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    private function reportHere(DuplicateTripGroups $finder): void
    {
        if (! Schema::hasTable('limo_bookings') || ! Schema::hasTable('limo_legs')) {
            $this->line('  Limousine is not installed here.');

            return;
        }

        $exact = $finder->exactGroups();
        $near = $finder->nearGroups($exact);

        $accidental = [];
        foreach ([...$exact, ...$near] as $group) {
            if ($finder->looksGenuinelyDuplicated($group)) {
                $accidental[] = $group;
            }
        }

        if ($accidental === []) {
            $this->line('  No accidental-looking duplicate groups to review.');

            return;
        }

        $ids = [];
        foreach ($accidental as $group) {
            foreach ($group as $row) {
                $ids[] = $row['id'];
            }
        }
        $ids = array_values(array_unique($ids));

        $withInvoice = LimoInvoice::query()->whereIn('booking_id', $ids)->pluck('booking_id')->all();
        $withReceipt = LimoReceipt::query()->whereIn('booking_id', $ids)->pluck('booking_id')->all();
        $hasMoney = array_flip(array_unique([...$withInvoice, ...$withReceipt]));

        $clean = [];
        $flagged = [];

        foreach ($accidental as $group) {
            $anyMoney = false;
            foreach ($group as $row) {
                if (isset($hasMoney[$row['id']])) {
                    $anyMoney = true;

                    break;
                }
            }

            if ($anyMoney) {
                $flagged[] = $group;
            } else {
                $clean[] = $group;
            }
        }

        $cleanRows = array_sum(array_map(static fn (array $g): int => count($g) - 1, $clean));
        $flaggedRows = array_sum(array_map(static fn (array $g): int => count($g) - 1, $flagged));

        $this->warn(sprintf(
            '  %d accidental-looking group(s) to review (%d row(s) total) — this is a SHORTLIST for a human, not a confirmed defect list.',
            count($accidental),
            $cleanRows + $flaggedRows,
        ));

        if ($clean === []) {
            $this->line('  No accidental-looking group is entirely free of recorded money.');
        } else {
            $this->warn(sprintf(
                '  %d group(s) — %d row(s) — with NO invoice or receipt on either side (the strongest candidates):',
                count($clean),
                $cleanRows,
            ));
            foreach ($clean as $group) {
                $this->printGroup($group);
            }
        }

        if ($flagged !== []) {
            $this->warn(sprintf(
                '  %d group(s) — %d row(s) — where money is ALREADY recorded on at least one side. Do not delete either side without checking with accounts first:',
                count($flagged),
                $flaggedRows,
            ));
            foreach ($flagged as $group) {
                $refs = [];
                $moneyRefs = [];
                foreach ($group as $row) {
                    $ref = (string) ($row['reference'] ?? ('#' . $row['id']));
                    $refs[] = $ref;
                    if (isset($hasMoney[$row['id']])) {
                        $moneyRefs[] = $ref;
                    }
                }
                $this->line(sprintf('    %s (money recorded on: %s)', implode(', ', $refs), implode(', ', $moneyRefs)));
            }
        }
    }

    /** @param list<array{id: int, reference: ?string, pax_name: ?string, notes: ?string, pickup_at: ?string, fare: float}> $group */
    private function printGroup(array $group): void
    {
        foreach ($group as $row) {
            $this->line(sprintf(
                '    %s — %s — %s BHD — pax: %s%s',
                $row['reference'] ?? ('#' . $row['id']),
                $row['pickup_at'] ?? '?',
                number_format($row['fare'], 3),
                $row['pax_name'] ?? '—',
                $row['notes'] !== null && $row['notes'] !== '' ? ' — notes: ' . $row['notes'] : '',
            ));
        }
        $this->line('');
    }
}
