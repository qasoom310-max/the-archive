<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            // The percentage applied to THIS order (snapshot of the matched
            // customer-discount rule at the time the customer was attached).
            $table->decimal('customer_discount_percent', 5, 2)->default(0)->after('change_due');
            // The money amount that percentage worked out to — stored so the
            // receipt + reporting don't have to re-derive it from the gross.
            $table->decimal('customer_discount_total', 12, 2)->default(0)->after('customer_discount_percent');
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->dropColumn(['customer_discount_percent', 'customer_discount_total']);
        });
    }
};
