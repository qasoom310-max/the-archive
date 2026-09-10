<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Petty cash: one float, and what each driver was handed from it.
 *
 * The manager holds a float; every day some of it goes out to a driver for
 * fuel, washes, parking. The float's balance is derived, never stored:
 * top-ups in, advances out, excess reimbursements out — a stored balance and
 * the rows it summarises always eventually disagree.
 *
 * An advance is a lifecycle, not a row of bookkeeping: issued by the manager,
 * confirmed by the accountant, backed by the paper receipts the driver hands
 * in, then settled — where the difference between what he was given and what
 * he can show becomes either a salary deduction or a reimbursement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('limo_petty_topups', function (Blueprint $table): void {
            $table->id();
            $table->date('date');
            $table->decimal('amount', 12, 3);
            // A name snapshot, not a FK — the row must still read right after
            // the account is renamed or removed.
            $table->string('added_by')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('limo_petty_advances', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->nullable()->unique();
            // Logical ref, like every cross-module link here: drivers live in
            // the shared rental_drivers table.
            $table->unsignedBigInteger('driver_id')->index();
            $table->date('date');
            $table->decimal('amount', 12, 3);
            $table->string('status', 20)->default('issued')->index();
            $table->string('issued_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('confirmed_by')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->string('settled_by')->nullable();
            // Snapshots taken at settlement, because a settled advance is a
            // record of a moment — later edits must not rewrite what was
            // decided about a man's salary.
            $table->decimal('receipts_total', 12, 3)->default(0);
            $table->decimal('shortfall', 12, 3)->default(0);
            $table->decimal('excess', 12, 3)->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('limo_petty_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('advance_id')->constrained('limo_petty_advances')->cascadeOnDelete();
            $table->date('date');
            $table->string('category', 20);
            $table->string('description')->nullable();
            $table->decimal('amount', 12, 3);
            $table->string('photo_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('limo_petty_lines');
        Schema::dropIfExists('limo_petty_advances');
        Schema::dropIfExists('limo_petty_topups');
    }
};
