<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_customer_discounts', function (Blueprint $table): void {
            $table->id();
            // The customer phone this discount is keyed to. Stored as the
            // admin typed it (human-readable); matching at the register
            // normalises both sides to digits, so "+973 33123456",
            // "97333123456" and "33123456" all resolve to the same entry.
            $table->string('phone')->index();
            // Open percentage off the order total (0–100). "Open number" =
            // the admin types any value in range.
            $table->decimal('discount_percent', 5, 2)->default(0);
            // Optional human label so the admin recognises whose number this
            // is (e.g. "VIP — Abu Ali"). Never shown to the customer.
            $table->string('label')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_customer_discounts');
    }
};
