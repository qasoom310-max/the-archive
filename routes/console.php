<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ─────────────────────────────────────────────────────────────────────────
// Queue drainer. Hostinger Cloud doesn't run persistent processes, so
// `queue:work` daemonised via systemd isn't an option. Instead we hook
// Laravel's scheduler: a single `* * * * * php artisan schedule:run`
// cron entry in hPanel triggers this every minute, draining whatever
// queued jobs accumulated since the last tick.
//
// `--stop-when-empty` exits cleanly once the queue is drained so the
// process doesn't outlive its cron slot. `--max-time=50` caps wall time
// at 50s so a worker can't meaningfully outlive its 60s cron slot.
//
// DELIBERATELY NO `withoutOverlapping()` here. We learned the hard way
// (prod queue frozen 2026-05-26 → 2026-06-09): combined with
// `runInBackground()`, the host can kill the detached worker AFTER
// `schedule:run` returns but BEFORE the chained `schedule:finish`
// releases the overlap mutex — orphaning the lock. Once orphaned, every
// subsequent `schedule:run` (cron AND manual) silently skips `queue:work`,
// and the stuck mutex outlived even its 24h TTL (sat stuck for 14 days).
// `--max-time=50` already bounds the worker, and the `database` queue
// driver reserves rows so a brief two-worker overlap can't double-process
// a job — so overlap protection buys us nothing but this failure mode.
//
// Symptom of NOT having a worker wired at all: queued WhatsApp messages
// (`whatsapp_messages_log.status='queued'`) never advance to `sent`,
// even though the order Chatter shows the send was queued — the actual
// HTTP POST to Meta's Graph API lives in `SendWhatsAppMessage`, which
// needs a worker to dequeue it.
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->runInBackground();

// Daily housekeeping: drop rendered WhatsApp receipt PNGs older than
// 7 days. Meta typically fetches the image within seconds of the send
// (and never re-fetches), so anything past a week is just clutter on
// disk. Kept locally rather than pushed to S3 — Hostinger Cloud has
// plenty of headroom and the per-order PNG is ~80–150 KB.
Schedule::call(function (): void {
    $disk = \Illuminate\Support\Facades\Storage::disk('public');
    $bucket = \Modules\Pos\Services\PosReceiptImageRenderer::BUCKET;
    $cutoff = now()->subDays(7)->getTimestamp();

    foreach ($disk->files($bucket) as $path) {
        if ($disk->lastModified($path) < $cutoff) {
            $disk->delete($path);
        }
    }
})->daily()->name('prune-whatsapp-receipts')->withoutOverlapping();

// Daily: lapse per-phone customer discounts that have gone 90 days with no
// qualifying purchase. The rolling window (`pos_customer_discounts.expires_at`)
// is pushed forward on every paid order that uses the discount; once it lapses
// this sweep flips `active` off so the admin list shows it as inactive.
// (The register stops applying it the moment it expires regardless — see
// `PosCustomerDiscount::findForPhone()` — so a missed cron tick is harmless.)
// Guarded so it no-ops when the POS module isn't installed.
Schedule::call(function (): void {
    if (! \Illuminate\Support\Facades\Schema::hasTable('pos_customer_discounts')) {
        return;
    }

    \Modules\Pos\Models\PosCustomerDiscount::deactivateLapsed();
})->daily()->name('expire-customer-discounts')->withoutOverlapping();

// Automated daily sales + stock PDF report. The cafe trades noon → 6 AM, so
// this fires at 6:10 AM (in the company timezone) and emails the report for the
// night that just closed (yesterday 12:00 → today 06:00) to the admin-managed
// recipient list. The timezone is read once at schedule-build time (guarded so
// it can't fail before the settings table exists); the window math + send are
// guarded again inside the closure so it no-ops with no POS / no recipients.
$reportTimezone = (string) config('app.timezone');

try {
    if (\Illuminate\Support\Facades\Schema::hasTable('ir_config_parameter')) {
        $companyTz = \App\Erp\Settings\Setting::get('company.timezone');
        if (is_string($companyTz) && $companyTz !== '') {
            $reportTimezone = $companyTz;
        }
    }
} catch (\Throwable) {
    // Settings unavailable (e.g. pre-migration) — fall back to app timezone.
}

Schedule::call(function (): void {
    if (! \Illuminate\Support\Facades\Schema::hasTable('pos_orders')
        || ! \Illuminate\Support\Facades\Schema::hasTable('report_recipients')) {
        return;
    }

    try {
        app(\Modules\Pos\Services\DailyReport::class)->sendLastClosedReport();
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('Daily report send failed: ' . $e->getMessage());
    }
})->dailyAt('06:10')->timezone($reportTimezone)->name('daily-pos-report')->withoutOverlapping();

// Daily: permanently purge trashed (soft-deleted) workspaces whose 14-day
// retention window has elapsed — removes the SQLite file + the registry row.
// A deleted database is restorable until this runs. Guarded so it no-ops when
// the workspaces table is absent (e.g. pre-migration). Runs in-process, so
// withoutOverlapping() is safe here (unlike the runInBackground queue:work).
Schedule::call(function (): void {
    if (! \Illuminate\Support\Facades\Schema::hasTable('workspaces')) {
        return;
    }

    try {
        app(\App\Erp\Tenancy\WorkspaceManager::class)->purgeExpired();
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('Workspace purge failed: ' . $e->getMessage());
    }
})->dailyAt('03:30')->name('purge-expired-workspaces')->withoutOverlapping();

// Daily whole-database backup (the in-app "Hostinger backup"): snapshot EVERY
// database (Main + each tenant workspace) to a gzipped file and prune snapshots
// past the 14-day retention window. Restorable from Settings → Backups. Runs
// in-process, so withoutOverlapping() is safe. Depends on the same hPanel
// schedule:run cron as the other daily tasks.
Schedule::command('backups:run')
    ->dailyAt('02:30')->name('backup-databases')->withoutOverlapping();
