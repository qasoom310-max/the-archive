<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global, dynamic system configuration — Odoo's `ir.config_parameter`.
 * `type` drives the settings-page control (string|bool|number); `group`
 * buckets keys into UI tabs; `label` is the human caption.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ir_config_parameter', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type')->default('string');   // string | bool | number
            $table->string('group')->default('General')->index();
            $table->string('label');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ir_config_parameter');
    }
};
