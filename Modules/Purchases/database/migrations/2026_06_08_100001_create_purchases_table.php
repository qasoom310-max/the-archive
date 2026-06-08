<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table): void {
            $table->id();
            // Auto-filled to BILL/<year>/<id> on create if left blank; the
            // user may instead type the supplier's own invoice number.
            $table->string('reference')->nullable()->unique();
            // Logical refs (no cross-module FK — same decoupling Inventory uses):
            $table->unsignedBigInteger('partner_id')->nullable()->index();   // vendor (contacts.partner)
            $table->unsignedBigInteger('user_id')->nullable()->index();      // who confirmed
            $table->date('date');
            $table->string('state', 16)->default('draft')->index();
            // true → debit Inventory asset; false → debit Purchase expense.
            $table->boolean('is_stock_purchase')->default(true);
            $table->decimal('total', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
