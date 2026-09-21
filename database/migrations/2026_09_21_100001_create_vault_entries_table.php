<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The logins a business runs on: hosting, the payment gateway, the social
 * accounts, the government portals.
 *
 * A CORE table, so every database gets its own. Each business keeps its own
 * credentials and one cannot see another's, exactly as settings and targets
 * already work.
 *
 * `password` and `note` are TEXT holding APP_KEY-encrypted ciphertext, the
 * same shape the WhatsApp, WooCommerce and payment-portal secrets already use.
 * The NOTE is encrypted too, deliberately: notes are where people write the
 * recovery codes and the security-question answers, which are worth as much as
 * the password itself.
 *
 * `owner_only` defaults TRUE. A new entry is private until somebody decides
 * otherwise - the safe direction for a default to fail in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vault_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('url')->nullable();
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->text('note')->nullable();
            $table->boolean('owner_only')->default(true);
            // Name snapshots, not foreign keys: the record of who added a
            // credential must still read correctly after that account is
            // renamed or deleted.
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            $table->timestamps();

            $table->index('name');
            $table->index('owner_only');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_entries');
    }
};
