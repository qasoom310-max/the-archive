<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Movement history for raw materials.
 *
 * `pos_ingredients.stock_on_hand` stays the authoritative on-hand figure — this
 * table is the record of HOW it got there. Until now every mutation (a confirmed
 * purchase, a production run, a sale consuming a recipe, a damage, a manual
 * adjustment) just moved that one number, leaving no way to answer "how much did
 * we buy?" or "how much did we actually use?" — and no way to tell a real
 * deduction from a stale form overwrite.
 *
 * `qty` is SIGNED and expressed in the ingredient's own stock units (bottles,
 * drums, kg…), not millilitres: positive = stock in, negative = stock out. It is
 * the delta actually applied, so summing every row for an ingredient reproduces
 * its on-hand — which makes a drift between the two detectable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_ingredient_moves', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('pos_ingredient_id')
                ->constrained('pos_ingredients')
                ->cascadeOnDelete();

            // Signed delta in stock units. Positive = in, negative = out.
            $table->decimal('qty', 14, 3);

            // purchase | production | sale | damage | adjustment — see
            // Modules\Pos\Enums\IngredientMoveKind.
            $table->string('kind', 16)->index();

            // Free-text origin: a purchase number, "Production #12", a POS order
            // reference. Plain string, NO FK — origins span several modules and
            // some of them (POS orders) can legitimately be deleted afterwards.
            $table->string('reference')->nullable();

            // On-hand immediately AFTER this move, so a history screen can show
            // a running balance without replaying every earlier row, and a stock
            // figure that drifts from the ledger is visible at a glance.
            $table->decimal('balance_after', 14, 3);

            // Who caused it. Logical ref (nullable, no FK): system-driven moves
            // have no user, and the row must outlive the account.
            $table->unsignedBigInteger('user_id')->nullable()->index();

            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_ingredient_moves');
    }
};
