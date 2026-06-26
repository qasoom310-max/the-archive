<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers can be an individual OR a company. A company carries a CR number +
 * a CR document (instead of a CPR), the company phone, and a contact person
 * with their own phone. Plus a country (ISO-2) so phones read with a country
 * code and a flag can be shown wherever the customer appears. Shared table —
 * both Rent A Car and Limousine read it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_customers', function (Blueprint $table): void {
            $table->string('type')->default('individual')->after('name');
            $table->string('country', 2)->nullable()->after('phone');
            $table->string('cr_number')->nullable()->after('cpr');
            $table->string('cr_document')->nullable()->after('cr_number');
            $table->string('contact_person')->nullable()->after('cr_document');
            $table->string('contact_phone')->nullable()->after('contact_person');
        });
    }

    public function down(): void
    {
        Schema::table('rental_customers', function (Blueprint $table): void {
            $table->dropColumn(['type', 'country', 'cr_number', 'cr_document', 'contact_person', 'contact_phone']);
        });
    }
};
