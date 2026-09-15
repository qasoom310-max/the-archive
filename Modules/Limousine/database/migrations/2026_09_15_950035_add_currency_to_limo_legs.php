<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A leg's rate is quoted in BHD by default, but an outside partner (a KSA
 * return run, a foreign chauffeur company) sometimes quotes in their own
 * currency. `rate` stays exactly what it always was — the BHD figure every
 * downstream calculation (line_total, net_amount, the booking fare, the
 * invoice) is built from — so nothing else in the app has to change.
 *
 * These three columns exist purely to remember WHAT WAS TYPED: the office
 * enters a rate in the chosen currency plus the exchange rate to BHD, and
 * `rate` is derived from the two (`quote_rate * exchange_rate`) rather than
 * typed directly. Re-opening the leg to edit it shows the original figures
 * back, not a BHD number that has to be reverse-engineered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('limo_legs', function (Blueprint $table): void {
            $table->string('currency', 3)->default('BHD')->after('rate');
            $table->decimal('quote_rate', 12, 3)->nullable()->after('currency');
            $table->decimal('exchange_rate', 12, 6)->nullable()->after('quote_rate');
        });
    }

    public function down(): void
    {
        Schema::table('limo_legs', function (Blueprint $table): void {
            $table->dropColumn(['currency', 'quote_rate', 'exchange_rate']);
        });
    }
};
