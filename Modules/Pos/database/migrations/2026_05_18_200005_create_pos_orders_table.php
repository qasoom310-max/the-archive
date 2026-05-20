<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('pos_session_id')->constrained('pos_sessions')->cascadeOnDelete();
            // partners table is provided by the Contacts module (a dependency).
            $table->foreignId('partner_id')->nullable()->constrained('partners')->nullOnDelete();
            $table->string('state')->default('draft'); // draft|paid|done|cancelled
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('paid_total', 12, 2)->default(0);
            $table->decimal('change_due', 12, 2)->default(0);
            $table->timestamp('ordered_at')->nullable();
            $table->timestamps();

            $table->index(['pos_session_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_orders');
    }
};
