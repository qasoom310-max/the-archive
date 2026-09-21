<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `limo_bookings.created_at` is deliberately backdated by
 * {@see \Modules\Limousine\Support\LegacyBookingImporter} to the booking's
 * ORIGINAL pickup/booking date from the old system — so it can never answer
 * "when did this row actually land in our database". `imported_at` is that
 * missing signal: the importer stamps it with the real wall-clock moment of
 * the import run, and every other creation path (the booking form, the
 * regular "Import" button) leaves it null. Null therefore means "created
 * live in this ERP"; a value means "brought over from the old system" — the
 * one reliable way to tell old vs. new trips apart regardless of what
 * reference number a trip ends up with.
 *
 * `limo_legs.created_at` needs no equivalent column — nothing ever backdates
 * it, so it already faithfully records each leg's real insertion time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('limo_bookings', function (Blueprint $table): void {
            $table->timestamp('imported_at')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('limo_bookings', function (Blueprint $table): void {
            $table->dropColumn('imported_at');
        });
    }
};
