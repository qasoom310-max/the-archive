<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogue of schedulable activity kinds. Mirrors Odoo's `mail.activity.type`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_activity_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('icon')->default('clipboard-document-check'); // heroicon name
            $table->unsignedInteger('default_days')->default(0);
            $table->unsignedInteger('sequence')->default(100);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_activity_types');
    }
};
