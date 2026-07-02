<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turn the limousine quotation into a header + unlimited line items (like the
 * old system): a customer/requested-by/prepared-by header, then one or more
 * priced lines (type, rate type, window, hours × units, vehicle, rate, discount,
 * VAT → net). The quotation `fare` holds the grand total (sum of line nets), so
 * convert-to-booking and the lists keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('limo_quotations', function (Blueprint $table): void {
            $table->date('quote_date')->nullable()->after('reference');
            $table->string('contact_person')->nullable()->after('customer_id');
            $table->string('requested_by')->nullable()->after('contact_person');
            $table->string('prepared_by')->nullable()->after('requested_by');
            $table->string('contact_number')->nullable()->after('prepared_by');
        });

        Schema::create('limo_quotation_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quotation_id')->constrained('limo_quotations')->cascadeOnDelete();
            $table->unsignedInteger('sequence')->default(0);
            $table->string('quote_type')->nullable();
            $table->string('rate_type')->nullable();
            $table->dateTime('date_from')->nullable();
            $table->dateTime('date_to')->nullable();
            $table->decimal('hours', 8, 2)->nullable();
            $table->unsignedInteger('units')->default(1);
            $table->string('vehicle')->nullable();
            $table->string('vehicle_details')->nullable();
            $table->decimal('rate', 12, 3)->default(0);
            $table->decimal('discount', 12, 3)->default(0);
            $table->decimal('vat', 12, 3)->default(0);
            $table->decimal('line_total', 12, 3)->default(0);
            $table->decimal('net_amount', 12, 3)->default(0);
            $table->timestamps();

            $table->index('quotation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('limo_quotation_lines');

        Schema::table('limo_quotations', function (Blueprint $table): void {
            $table->dropColumn(['quote_date', 'contact_person', 'requested_by', 'prepared_by', 'contact_number']);
        });
    }
};
