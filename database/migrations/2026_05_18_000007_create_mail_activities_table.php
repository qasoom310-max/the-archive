<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planned, schedulable activities attached to any record. Mirrors Odoo's
 * `mail.activity`. Completed activities are kept (done=true) so they remain
 * in the historical log instead of vanishing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_activities', function (Blueprint $table): void {
            $table->id();
            $table->morphs('messageable');
            $table->foreignId('mail_activity_type_id')
                ->constrained('mail_activity_types')->cascadeOnDelete();
            $table->string('summary');
            $table->text('note')->nullable();
            $table->date('due_date');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name')->nullable();    // assignee display cache
            $table->boolean('done')->default(false);
            $table->timestamp('done_at')->nullable();
            $table->string('done_by_name')->nullable();
            $table->timestamps();

            // Explicit short name — MySQL/MariaDB caps identifiers at 64 chars
            // and the Laravel-auto-generated name from these 4 columns is 71.
            // SQLite (dev) allows much longer; CLAUDE.md §6 calls this out.
            $table->index(
                ['messageable_type', 'messageable_id', 'done', 'due_date'],
                'mail_activities_target_due_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_activities');
    }
};
