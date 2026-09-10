<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer's signature on a leg's Service Order — the proof that the driver
 * actually reached them and the trip was used.
 *
 * The customer signs from an emailed link rather than on paper, so alongside the
 * drawn signature we keep WHEN it was signed, the name typed with it, and the IP
 * it came from. Those three are what make it evidence rather than just an image:
 * a disputed trip can be answered with a time and an origin, not only a squiggle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('limo_legs', function (Blueprint $table): void {
            // PNG on the public disk, drawn by the customer on their phone.
            $table->string('signature_path')->nullable();
            $table->dateTime('signed_at')->nullable();
            $table->string('signed_name')->nullable();
            $table->string('signed_ip', 45)->nullable();   // 45 = INET6 length
            // When the link was last emailed, so the office can see it was sent.
            $table->dateTime('service_order_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('limo_legs', function (Blueprint $table): void {
            $table->dropColumn([
                'signature_path', 'signed_at', 'signed_name', 'signed_ip', 'service_order_sent_at',
            ]);
        });
    }
};
