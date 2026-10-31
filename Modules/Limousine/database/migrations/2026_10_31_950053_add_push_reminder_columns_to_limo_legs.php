<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The staff app's phone notifications for a trip with no car or driver
 * ({@see \Modules\Limousine\Services\AppCrewReminders}): when operations were
 * last reminded (again every 10 minutes) and when the owner was told the
 * 12-hour promise was missed (once). Their own columns, so they never mix with
 * the WhatsApp reminders' timing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('limo_legs', function (Blueprint $table): void {
            if (! Schema::hasColumn('limo_legs', 'push_reminded_at')) {
                $table->timestamp('push_reminded_at')->nullable();
            }
            if (! Schema::hasColumn('limo_legs', 'push_escalated_at')) {
                $table->timestamp('push_escalated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('limo_legs', function (Blueprint $table): void {
            foreach (['push_reminded_at', 'push_escalated_at'] as $column) {
                if (Schema::hasColumn('limo_legs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
