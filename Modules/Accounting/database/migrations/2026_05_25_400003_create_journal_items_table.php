<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_items', function (Blueprint $table): void {
            $table->id();

            // The line's parent header. Cascade — deleting the entry
            // removes its lines atomically (only draft entries should
            // ever be deleted; posted entries are reversed, not removed).
            $table->foreignId('journal_entry_id')
                ->constrained('journal_entries')
                ->cascadeOnDelete();

            // Which COA account this line touches. Restrict — an account
            // with movements can never be deleted (matches Odoo).
            $table->foreignId('account_id')
                ->constrained('accounts')
                ->restrictOnDelete();

            // Money columns: decimal(15,2). 15 digits is comfortable for
            // currencies up to trillions; 2 decimals enforces the project-
            // wide policy (memory: all dinar formatting is 2 dp now).
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);

            // Vendor / customer this line relates to — used by aged-
            // receivable / aged-payable reports and account-statement
            // views. Logical ref: nullable, indexed, NO FK (Contacts is
            // a separate module, same convention as pos_orders.partner_id).
            $table->unsignedBigInteger('partner_id')->nullable()->index();

            // Free-text per-line memo (optional).
            $table->string('memo')->nullable();

            $table->timestamps();

            // Driving index for ledger / trial-balance queries:
            // "all items for this account, in date order via the entry".
            $table->index(['account_id', 'journal_entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_items');
    }
};
