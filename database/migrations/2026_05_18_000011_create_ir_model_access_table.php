<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-model CRUD access rules. Mirrors Odoo's `ir.model.access`.
 * A null group_id = the rule applies to every authenticated user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ir_model_access', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('model'); // ir_model.model dotted id, e.g. "contacts.partner"
            $table->foreignId('group_id')->nullable()
                ->constrained('res_groups')->cascadeOnDelete();
            $table->boolean('perm_read')->default(false);
            $table->boolean('perm_write')->default(false);
            $table->boolean('perm_create')->default(false);
            $table->boolean('perm_unlink')->default(false);
            $table->timestamps();

            $table->index(['model', 'group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ir_model_access');
    }
};
