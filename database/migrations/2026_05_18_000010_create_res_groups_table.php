<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Security groups / roles. Mirrors Odoo's `res.groups`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('res_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('res_group_user', function (Blueprint $table): void {
            $table->foreignId('group_id')->constrained('res_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['group_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('res_group_user');
        Schema::dropIfExists('res_groups');
    }
};
