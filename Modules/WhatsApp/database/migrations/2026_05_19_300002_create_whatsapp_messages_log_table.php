<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lifecycle log for every WhatsApp message. Outbound rows are created
 * `queued` by the service, flipped to `sent`/`failed` by the job, then
 * advanced to `delivered`/`read` by the webhook (matched on `wamid`).
 * Inbound customer replies are stored as `received` rows. `related_*`
 * is the (future) link back to the originating ERP document for Chatter.
 * Driver-agnostic column types only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages_log', function (Blueprint $table): void {
            $table->id();
            $table->string('wamid')->nullable()->index();        // Meta message id
            $table->string('direction')->default('outbound')->index(); // outbound|inbound
            $table->string('contact_number')->nullable()->index();
            $table->string('message_type')->nullable();          // template|text|image|…
            $table->string('template_name')->nullable();
            $table->string('status')->default('queued')->index(); // queued|sent|delivered|read|failed|received
            $table->text('error')->nullable();
            $table->text('payload')->nullable();                  // JSON: sent body / inbound message
            $table->string('related_type')->nullable();           // ERP document correlation (later)
            $table->unsignedBigInteger('related_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages_log');
    }
};
