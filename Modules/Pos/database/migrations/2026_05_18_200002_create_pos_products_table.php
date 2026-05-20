<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0); // percent, e.g. 20.00
            $table->string('barcode')->nullable()->unique();
            $table->foreignId('pos_category_id')->nullable()
                ->constrained('pos_categories')->nullOnDelete();
            $table->string('image_path')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_products');
    }
};
