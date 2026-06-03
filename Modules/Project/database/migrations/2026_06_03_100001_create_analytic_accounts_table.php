<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal analytic ledger. The system had no analytic / cost-centre
 * dimension before the Project module; this is the smallest faithful
 * version of Odoo's `account.analytic.account` so a project can accrue
 * labour cost outside the financial GL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytic_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code', 32)->nullable()->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytic_accounts');
    }
};
