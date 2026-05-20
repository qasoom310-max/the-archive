<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registry of model attributes / relationships. Mirrors Odoo's `ir.model.fields`.
 * `ttype` is the abstract field type: char|text|integer|float|boolean|date|
 * datetime|binary|selection|many2one|one2many|many2many.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ir_model_fields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ir_model_id')->constrained('ir_model')->cascadeOnDelete();
            $table->string('name');                 // attribute / column name
            $table->string('label');
            $table->string('ttype');
            $table->string('relation')->nullable(); // target model dotted id (relational ttypes)
            $table->boolean('required')->default(false);
            $table->boolean('readonly')->default(false);
            $table->boolean('is_custom')->default(false);
            $table->json('selection')->nullable();  // list<{value,label}> for selection ttype
            $table->string('help')->nullable();
            $table->unsignedInteger('sequence')->default(100);
            $table->timestamps();

            $table->unique(['ir_model_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ir_model_fields');
    }
};
