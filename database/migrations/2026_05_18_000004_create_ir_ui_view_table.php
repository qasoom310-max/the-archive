<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stored, data-driven view metadata. Mirrors Odoo's `ir.ui.view`.
 * `arch` holds the structural layout definition (columns, fields, buckets)
 * so List / Kanban / Form layouts can be modified without code changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ir_ui_view', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('model')->nullable();        // ir_model.model dotted id
            $table->string('type');                     // list|kanban|form|search
            $table->json('arch');                       // structural definition
            $table->string('module')->nullable();
            $table->unsignedInteger('priority')->default(16); // Odoo default priority
            $table->foreignId('inherit_id')->nullable()
                ->constrained('ir_ui_view')->nullOnDelete();
            $table->string('mode')->default('primary'); // primary|extension
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['model', 'type', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ir_ui_view');
    }
};
