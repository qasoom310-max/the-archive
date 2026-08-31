<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A driver's papers: the CPR and licence themselves, and when the licence runs
 * out.
 *
 * The same shape a vehicle already has — a document alongside the date it
 * expires — because the question is the same one: may this go out today? A
 * licence number on its own says a driver had one once; the expiry is what
 * decides whether they can be given a trip now.
 *
 * On `rental_drivers`, which is the single driver store both transport apps
 * read (see LimoDriver), so a licence renewed in one is renewed in the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_drivers', function (Blueprint $table): void {
            $table->date('license_expiry')->nullable()->after('license_no');
            $table->string('license_doc')->nullable()->after('license_expiry');
            $table->string('cpr_doc')->nullable()->after('cpr');
        });
    }

    public function down(): void
    {
        Schema::table('rental_drivers', function (Blueprint $table): void {
            $table->dropColumn(['license_expiry', 'license_doc', 'cpr_doc']);
        });
    }
};
