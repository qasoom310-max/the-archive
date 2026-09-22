<?php

declare(strict_types=1);

namespace Modules\Limousine\Console;

use App\Erp\Backup\DatabaseBackup;
use App\Erp\Tenancy\WorkspaceManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoQuotation;

/**
 * Deletes EVERY Limousine booking (all statuses), trip, quotation, invoice
 * and receipt in ONE database, so the owner can start again from a backup.
 *
 * Built for the 2026-09-22 cutover, on the owner's explicit instruction.
 * Payment links and coupon-credit use that point at those records go too;
 * trip expenses and coupons themselves are kept with their link cleared.
 * Customers, drivers, locations, petty cash and the fleet are untouched.
 *
 * Safe by default: without --force it only counts. With --force it first
 * takes a whole-database snapshot (restorable from Activity Log → Backups),
 * then deletes everything in one transaction.
 */
final class WipeDocuments extends Command
{
    protected $signature = 'limo:wipe-documents
        {--workspace= : The workspace id to wipe (required)}
        {--force : Actually delete — without it the command only counts}';

    protected $description = 'Delete every Limousine booking, trip, quotation, invoice and receipt in one database.';

    public function handle(WorkspaceManager $workspaces): int
    {
        $option = $this->option('workspace');
        $id = is_scalar($option) ? (string) $option : '';
        if (! ctype_digit($id)) {
            $this->error('Pass --workspace=<id>. This command never guesses which database to wipe.');

            return self::FAILURE;
        }

        $workspace = $workspaces->find((int) $id);
        if ($workspace === null) {
            $this->error("No workspace with id {$id}.");

            return self::FAILURE;
        }

        $this->info("Database: {$workspace->name} (#{$workspace->id})");

        $run = fn (): int => $this->wipeHere((bool) $this->option('force'));

        if ($workspace->is_main) {
            return $run();
        }

        $path = $workspace->databasePath();
        if ($path === null || ! is_file($path)) {
            $this->error('Its database file is missing.');

            return self::FAILURE;
        }

        return $workspaces->withTenant($path, $run);
    }

    private function wipeHere(bool $force): int
    {
        if (! Schema::hasTable('limo_bookings')) {
            $this->warn('Limousine is not installed in this database. Nothing to do.');

            return self::SUCCESS;
        }

        $morphs = [
            (new LimoBooking())->getMorphClass(),
            (new LimoQuotation())->getMorphClass(),
            (new LimoInvoice())->getMorphClass(),
        ];

        $counts = [
            'Bookings' => DB::table('limo_bookings')->count(),
            'Trips (booking + quotation legs)' => DB::table('limo_legs')->whereIn('legable_type', $morphs)->count(),
            'Quotations' => DB::table('limo_quotations')->count(),
            'Invoices' => DB::table('limo_invoices')->count(),
            'Receipts' => DB::table('limo_receipts')->count(),
            'Payment links' => $this->count('limo_payment_links'),
            'Coupon-credit uses' => Schema::hasTable('limo_coupon_redemptions')
                ? DB::table('limo_coupon_redemptions')->whereIn('redeemable_type', $morphs)->count()
                : 0,
        ];

        foreach ($counts as $label => $n) {
            $this->line(sprintf('  %-34s %d', $label, $n));
        }

        if (! $force) {
            $this->warn('Dry run — nothing was deleted. Run again with --force to delete.');

            return self::SUCCESS;
        }

        $snapshot = app(DatabaseBackup::class)->snapshot();
        $this->info('Safety snapshot taken: '.basename((string) $snapshot));

        DB::transaction(function () use ($morphs): void {
            if (Schema::hasTable('limo_coupon_redemptions')) {
                DB::table('limo_coupon_redemptions')->whereIn('redeemable_type', $morphs)->delete();
            }
            if (Schema::hasTable('limo_coupons') && Schema::hasColumn('limo_coupons', 'limo_booking_id')) {
                DB::table('limo_coupons')->whereNotNull('limo_booking_id')->update(['limo_booking_id' => null]);
            }
            if (Schema::hasTable('limo_payment_links')) {
                if (Schema::hasTable('whatsapp_assistant_payment_links')) {
                    DB::table('whatsapp_assistant_payment_links')->delete();
                }
                DB::table('limo_payment_links')->delete();
            }
            if (Schema::hasTable('limo_expenses')) {
                DB::table('limo_expenses')->whereNotNull('booking_id')->update(['booking_id' => null]);
            }

            DB::table('limo_receipts')->delete();
            DB::table('limo_invoices')->delete();
            DB::table('limo_legs')->whereIn('legable_type', $morphs)->delete();
            if (Schema::hasTable('limo_quotation_lines')) {
                DB::table('limo_quotation_lines')->delete();
            }
            DB::table('limo_quotations')->delete();
            DB::table('limo_bookings')->delete();
        });

        $this->info('Deleted. Bookings left: '.DB::table('limo_bookings')->count().'.');

        return self::SUCCESS;
    }

    private function count(string $table): int
    {
        return Schema::hasTable($table) ? DB::table($table)->count() : 0;
    }
}
