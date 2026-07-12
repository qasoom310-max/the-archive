<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Erp\Tenancy\WorkspaceManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Services\PosSaleEraser;

/**
 * Delete finalized POS sales from a workspace — orders, their lines, payments,
 * and the journal entries they generated (sale + delivery cost). Used to wipe
 * TEST sales made while setting a shop up, so the daily reports start clean.
 *
 * Safety:
 *  - Dry-run by default; nothing is deleted without --confirm.
 *  - Targets one workspace, passed explicitly by id (never guessed from a
 *    cookie), and prints a per-day breakdown so the operator can eyeball the
 *    total before confirming.
 *  - Optional --from / --to (on the sale's ordered_at date) to scope to
 *    specific days.
 *  - Leaves sessions, draft orders, and stock untouched (deleting a sale does
 *    not restore consumed stock — that is set separately in the Stock Report).
 */
final class ClearPosSales extends Command
{
    protected $signature = 'pos:clear-sales
        {--workspace= : Tenant workspace id to operate on (omit to list workspaces)}
        {--from= : Only sales on/after this date (YYYY-MM-DD)}
        {--to= : Only sales on/before this date (YYYY-MM-DD)}
        {--confirm : Actually delete (default is a dry run)}';

    protected $description = 'Delete finalized POS sales (test data) from a workspace. Dry-run unless --confirm.';

    public function handle(WorkspaceManager $manager): int
    {
        $wsOption = $this->option('workspace');

        if ($wsOption === null || $wsOption === '') {
            $this->info('Pass --workspace=ID. Available workspaces:');
            foreach ($manager->all() as $w) {
                $this->line(sprintf('  %-4d %s%s', $w->id, $w->name, $w->is_main ? '  (main)' : ''));
            }

            return self::FAILURE;
        }

        $workspace = $manager->find((int) $wsOption);
        if ($workspace === null) {
            $this->error("Workspace {$wsOption} not found.");

            return self::FAILURE;
        }

        $this->line("Workspace: {$workspace->name} (#{$workspace->id})");

        if ($workspace->is_main) {
            return $this->clear();
        }

        $path = $workspace->databasePath();
        if ($path === null || ! is_file($path)) {
            $this->error("Database file for {$workspace->name} is missing.");

            return self::FAILURE;
        }

        return $manager->withTenant($path, fn (): int => $this->clear());
    }

    private function clear(): int
    {
        if (! Schema::hasTable('pos_orders')) {
            $this->warn('This workspace has no POS tables — nothing to clear.');

            return self::SUCCESS;
        }

        $from = $this->option('from');
        $to = $this->option('to');

        $orders = PosOrder::query()
            ->where('state', OrderState::Done->value)
            ->when(is_string($from) && $from !== '', fn ($q) => $q->whereDate('ordered_at', '>=', $from))
            ->when(is_string($to) && $to !== '', fn ($q) => $q->whereDate('ordered_at', '<=', $to))
            ->orderBy('ordered_at')
            ->get();

        if ($orders->isEmpty()) {
            $this->info('No finalized POS sales match — nothing to clear.');

            return self::SUCCESS;
        }

        $confirm = (bool) $this->option('confirm');
        $total = (float) $orders->sum('total');

        $this->newLine();
        $this->info(($confirm ? 'Deleting ' : 'DRY RUN — would delete ')
            . $orders->count() . ' finalized sale(s), total ' . number_format($total, 2) . ':');

        /** @var array<string, array{count: int, total: float}> $byDate */
        $byDate = [];
        foreach ($orders as $order) {
            $day = $order->ordered_at?->toDateString() ?? '(no date)';
            $byDate[$day] ??= ['count' => 0, 'total' => 0.0];
            $byDate[$day]['count']++;
            $byDate[$day]['total'] += (float) $order->total;
        }
        ksort($byDate);
        foreach ($byDate as $day => $agg) {
            $this->line(sprintf('  %s  %d order(s)  %s', $day, $agg['count'], number_format($agg['total'], 2)));
        }

        if (! $confirm) {
            $this->newLine();
            $this->warn('Nothing deleted. Re-run with --confirm to apply.');

            return self::SUCCESS;
        }

        $eraser = app(PosSaleEraser::class);
        foreach ($orders as $order) {
            $eraser->erase($order);
        }

        $this->newLine();
        $this->info('Cleared ' . $orders->count() . ' sale(s). Sessions and stock were left untouched.');

        return self::SUCCESS;
    }
}
