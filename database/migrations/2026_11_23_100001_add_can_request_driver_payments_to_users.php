<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Who may be named as having asked for a limousine driver's payment. The owner
 * named Ali and Abbas (2026-11-23); they are matched here by first name, once.
 * The owner changes the list on the Driver payments page.
 */
return new class extends Migration
{
    private const FIRST_NAMES = ['ali', 'abbas'];

    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasColumn('users', 'can_request_driver_payments')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('can_request_driver_payments')->default(false);
            });
        }

        $named = [];
        foreach (DB::table('users')->get(['id', 'name']) as $user) {
            $first = strtolower(trim((string) strtok(trim((string) $user->name), ' ')));
            if (in_array($first, self::FIRST_NAMES, true)) {
                DB::table('users')->where('id', $user->id)->update(['can_request_driver_payments' => true]);
                $named[] = (string) $user->name;
            }
        }

        if ($named !== []) {
            Log::info('Driver payment requesters: ' . implode(', ', $named) . ' in ' . DB::connection()->getDatabaseName());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'can_request_driver_payments')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('can_request_driver_payments');
            });
        }
    }
};
