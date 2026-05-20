<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Threaded messages / notes / audit-log entries attached to any record.
 * Mirrors Odoo's `mail.message`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_messages', function (Blueprint $table): void {
            $table->id();
            $table->morphs('messageable');
            $table->string('type')->default('comment'); // comment|note|log
            $table->string('subject')->nullable();
            $table->text('body');
            $table->unsignedBigInteger('author_id')->nullable();
            $table->string('author_name')->nullable();   // display cache (no auth system yet)
            $table->json('tracking')->nullable();         // field-change diff for log entries
            $table->timestamps();

            $table->index(['messageable_type', 'messageable_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_messages');
    }
};
