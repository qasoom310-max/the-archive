<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registry of available / installed application modules.
 * Mirrors Odoo's `ir.module.module`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ir_module', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();              // technical name, e.g. "contacts"
            $table->string('display_name');
            $table->string('summary')->nullable();
            $table->text('description')->nullable();
            $table->string('version')->default('1.0.0');
            $table->string('installed_version')->nullable();
            $table->string('author')->nullable();
            $table->string('category')->nullable();
            $table->string('icon')->nullable();
            $table->json('depends')->nullable();           // list<string> of module names
            $table->boolean('application')->default(false); // shows in the app switcher
            $table->boolean('auto_install')->default(false);
            $table->string('state')->default('uninstalled'); // uninstalled|installed|to_upgrade
            $table->unsignedInteger('sequence')->default(100);
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();

            $table->index(['state', 'application']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ir_module');
    }
};
