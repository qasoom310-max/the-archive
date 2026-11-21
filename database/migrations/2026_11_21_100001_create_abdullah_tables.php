<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Abdullah, the staff assistant: what he said and to whom, what he is chasing,
 * and each person's language with him.
 *
 * Messages are kept for good (nothing deletes them): the owner reads them back
 * on the Abdullah page to see how he speaks to the staff.
 *
 * Languages the owner chose: Prejita Malayalam, Via Tagalog, Mac English,
 * everyone else Arabic. Set here once by first name, only where nothing is set;
 * the owner or the person changes it afterwards.
 */
return new class extends Migration
{
    private const LANGUAGES = ['prejita' => 'ml', 'via' => 'tl', 'mac' => 'en'];

    public function up(): void
    {
        if (! Schema::hasTable('abdullah_messages')) {
            Schema::create('abdullah_messages', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                // abdullah | user
                $table->string('role', 16)->default('abdullah');
                // chat | booking | check | trip | escalation
                $table->string('kind', 24)->default('chat');
                // box | email
                $table->string('channel', 8)->default('box');
                $table->string('email_to')->nullable();
                $table->string('alert_key')->nullable()->index();
                $table->string('language', 8)->default('ar');
                $table->json('facts')->nullable();
                // Null until written: a box message is written when its reader is on screen.
                $table->text('body')->nullable();
                $table->boolean('by_ai')->default(false);
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'read_at']);
            });
        }

        if (! Schema::hasTable('abdullah_alerts')) {
            Schema::create('abdullah_alerts', function (Blueprint $table): void {
                $table->id();
                $table->string('key')->unique();
                $table->string('kind', 24);
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->unsignedInteger('sent_count')->default(0);
                $table->timestamp('last_sent_at')->nullable();
                $table->timestamp('acknowledged_at')->nullable();
                $table->unsignedBigInteger('acknowledged_by')->nullable();
                $table->timestamp('escalated_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table): void {
                if (! Schema::hasColumn('users', 'abdullah_language')) {
                    $table->string('abdullah_language', 8)->nullable();
                }
                if (! Schema::hasColumn('users', 'abdullah_off')) {
                    $table->boolean('abdullah_off')->default(false);
                }
            });

            foreach (DB::table('users')->whereNull('abdullah_language')->get(['id', 'name']) as $user) {
                $first = strtolower(trim((string) strtok(trim((string) $user->name), ' ')));
                if (isset(self::LANGUAGES[$first])) {
                    DB::table('users')->where('id', $user->id)->update(['abdullah_language' => self::LANGUAGES[$first]]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('abdullah_alerts');
        Schema::dropIfExists('abdullah_messages');

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table): void {
                foreach (['abdullah_language', 'abdullah_off'] as $column) {
                    if (Schema::hasColumn('users', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
