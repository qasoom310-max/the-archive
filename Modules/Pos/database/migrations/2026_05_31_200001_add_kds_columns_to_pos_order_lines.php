<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KDS state per line:
 *   - `notes` — cashier free-text per line (e.g. "بدون مخلل").
 *   - `prep_status` — pending → preparing → ready → completed.
 *     `null` means this line never enters the KDS (the product's
 *     category has no station).
 *   - `prep_sent_at` — when the line was queued to a station (set on
 *     order finalisation). Drives the "X mins ago" + "late > 15 min"
 *     visual cue on the ticket card.
 *   - `prep_started_at` / `prep_ready_at` / `prep_completed_at` —
 *     transition timestamps for future SLA / throughput reports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_order_lines', function (Blueprint $table): void {
            $table->text('notes')->nullable()->after('name');
            $table->string('prep_status', 16)->nullable()->after('total');
            $table->timestamp('prep_sent_at')->nullable()->after('prep_status');
            $table->timestamp('prep_started_at')->nullable()->after('prep_sent_at');
            $table->timestamp('prep_ready_at')->nullable()->after('prep_started_at');
            $table->timestamp('prep_completed_at')->nullable()->after('prep_ready_at');

            // Single hot index: KDS polls by (station-derived) status + sent_at.
            // Station itself isn't on the line — joined via product → category.
            // Index on (prep_status, prep_sent_at) keeps the poll cheap.
            $table->index(['prep_status', 'prep_sent_at'], 'pol_prep_status_sent_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pos_order_lines', function (Blueprint $table): void {
            $table->dropIndex('pol_prep_status_sent_idx');
            $table->dropColumn([
                'notes', 'prep_status', 'prep_sent_at',
                'prep_started_at', 'prep_ready_at', 'prep_completed_at',
            ]);
        });
    }
};
