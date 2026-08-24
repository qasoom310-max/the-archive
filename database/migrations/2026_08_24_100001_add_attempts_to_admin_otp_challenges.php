<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wrong-code counter for the admin email OTP.
 *
 * The 6-digit code guarding user deletion and database deletion could be
 * guessed indefinitely — which is exactly the attack it exists to stop, since
 * reaching the prompt at all means the session is already compromised.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('admin_otp_challenges') || Schema::hasColumn('admin_otp_challenges', 'attempts')) {
            return;
        }

        Schema::table('admin_otp_challenges', function (Blueprint $table): void {
            $table->unsignedTinyInteger('attempts')->default(0)->after('code_hash');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('admin_otp_challenges') || ! Schema::hasColumn('admin_otp_challenges', 'attempts')) {
            return;
        }

        Schema::table('admin_otp_challenges', function (Blueprint $table): void {
            $table->dropColumn('attempts');
        });
    }
};
