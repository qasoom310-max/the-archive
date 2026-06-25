<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extra charge: a free-form additional amount the desk can add at return time —
 * an extra rental day, a cleaning fee, a fine, anything. It's part of the
 * taxable supply (VAT applies, like the rental itself), and an optional note
 * records what it was for so it can be shown on the receipt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_orders', function (Blueprint $table): void {
            $table->decimal('extra_charge', 10, 3)->default(0)->after('fuel_charge');
            $table->string('extra_charge_note')->nullable()->after('extra_charge');
        });
    }

    public function down(): void
    {
        Schema::table('rental_orders', function (Blueprint $table): void {
            $table->dropColumn(['extra_charge', 'extra_charge_note']);
        });
    }
};
