<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port the full booking sheet from the old system: booking type / window, PAX
 * and flight details, pickup & drop-off addresses, the money breakdown
 * (amount − discount = net, plus advance), payment method, car count / details,
 * and the requested-by / prepared-by sign-off. `fare` stays as the net amount so
 * invoices and dashboards keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('limo_bookings', function (Blueprint $table): void {
            $table->string('booking_type')->nullable()->after('reference');
            $table->dateTime('booking_to')->nullable()->after('pickup_at');
            $table->string('contact_person')->nullable()->after('customer_id');
            $table->string('company_reference')->nullable()->after('contact_person');
            $table->string('pax_name')->nullable()->after('company_reference');
            $table->string('pax_contact')->nullable()->after('pax_name');
            $table->string('flight_number')->nullable()->after('pax_contact');
            $table->string('email')->nullable()->after('flight_number');
            $table->text('pickup_address')->nullable()->after('pickup_location_id');
            $table->text('dropoff_address')->nullable()->after('dropoff_location_id');
            $table->decimal('amount', 12, 3)->default(0)->after('fare');
            $table->decimal('discount', 12, 3)->default(0)->after('amount');
            $table->decimal('advance', 12, 3)->default(0)->after('discount');
            $table->string('rate_type')->nullable()->after('advance');
            $table->string('payment_method')->nullable()->after('rate_type');
            $table->unsignedInteger('num_cars')->default(1)->after('payment_method');
            $table->string('car_details')->nullable()->after('num_cars');
            $table->string('requested_by')->nullable()->after('car_details');
            $table->string('prepared_by')->nullable()->after('requested_by');
        });

        // Existing bookings only carried the net fare — seed the gross amount from
        // it so amount − discount (0) still equals the net that's already stored.
        DB::table('limo_bookings')->where('amount', 0)->update(['amount' => DB::raw('fare')]);
    }

    public function down(): void
    {
        Schema::table('limo_bookings', function (Blueprint $table): void {
            $table->dropColumn([
                'booking_type', 'booking_to', 'contact_person', 'company_reference',
                'pax_name', 'pax_contact', 'flight_number', 'email',
                'pickup_address', 'dropoff_address', 'amount', 'discount', 'advance',
                'rate_type', 'payment_method', 'num_cars', 'car_details',
                'requested_by', 'prepared_by',
            ]);
        });
    }
};
