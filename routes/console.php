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
// at 50s so two ticks can never overlap (cron fires every 60s). Without
// `withoutOverlapping()` two slow jobs could otherwise stack workers;
// the lock guarantees only one drainer is live at a time.
//
// Symptom of NOT having this wired: queued WhatsApp messages
// (`whatsapp_messages_log.status='queued'`) never advance to `sent`,
// even though the order Chatter shows the send was queued — the actual
// HTTP POST to Meta's Graph API lives in `SendWhatsAppMessage`, which
// needs a worker to dequeue it.
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping()
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
