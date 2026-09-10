<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a driver is paid: a company driver, or a commission driver working off
 * the office's fixed commission scale (25 / 15 / 10 / 7 / 5%).
 *
 * `commission_rate` is a plain decimal column, NOT an enum cast — the same
 * reasoning that already forced `PosCategory.station` off an enum cast: the
 * engine form's blank "—" option round-trips as an empty string, and an
 * enum cast throws on that at `setAttribute` time, before any hook can
 * normalise it. The scale is enforced instead by the engine's own `in:`
 * rule, generated from the form's `select` options (see
 * `FormView::rules()`) — no free-form rate can ever reach this column.
 *
 * On `rental_drivers`, the single driver store both transport apps read (see
 * LimoDriver) — pay arrangement is a fact about the PERSON, not about which
 * app dispatches them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_drivers', function (Blueprint $table): void {
            $table->string('pay_type', 16)->default('company')->after('active');
            $table->decimal('commission_rate', 5, 2)->nullable()->after('pay_type');
        });
    }

    public function down(): void
    {
        Schema::table('rental_drivers', function (Blueprint $table): void {
            $table->dropColumn(['pay_type', 'commission_rate']);
        });
    }
};
