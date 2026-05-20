<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registry of system models. Mirrors Odoo's `ir.model`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ir_model', function (Blueprint $table): void {
            $table->id();
            $table->string('model')->unique();   // dotted technical id, e.g. "contacts.partner"
            $table->string('name');              // human label, e.g. "Contact"
            $table->string('class');             // Eloquent FQCN
            $table->string('table');             // physical DB table
            $table->string('module')->nullable(); // owning module (ir_module.name)
            $table->string('description')->nullable();
            $table->boolean('is_custom')->default(false);
            $table->timestamps();

            $table->index('module');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ir_model');
    }
};
