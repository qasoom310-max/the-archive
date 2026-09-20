<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The WhatsApp staff assistant: an internal bot that lets an authorised
 * employee quote, book, pull documents and raise payment links by chatting.
 *
 * Per database, like every other integration. The Meta credentials are NOT
 * duplicated here — they stay in `whatsapp_configuration`. This adds only
 * what the assistant itself needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Single row: the AI key (encrypted), model, and the master kill-switch.
        Schema::create('whatsapp_assistant_configuration', function (Blueprint $table): void {
            $table->id();
            $table->text('ai_api_key')->nullable();   // encrypted
            $table->string('ai_model', 64)->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });

        // Which WhatsApp numbers may use the assistant, and as which ERP user.
        Schema::create('whatsapp_assistant_staff', function (Blueprint $table): void {
            $table->id();
            $table->string('phone', 32)->unique();    // digits only, country code included
            $table->unsignedBigInteger('user_id')->index(); // logical ref to users
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('whatsapp_conversations', function (Blueprint $table): void {
            $table->id();
            $table->string('wa_id', 32)->unique();    // the staff member's WhatsApp id (digits)
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('language', 2)->default('en');
            $table->string('state', 32)->nullable();  // e.g. awaiting_confirmation
            $table->json('draft')->nullable();         // the action waiting for YES
            $table->json('history')->nullable();       // recent text turns for the AI
            $table->timestamp('last_msg_at')->nullable();
            $table->timestamps();
        });

        Schema::create('whatsapp_messages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('conversation_id')->nullable()->index();
            $table->string('direction', 8);           // in | out
            $table->string('wa_message_id', 191)->nullable()->unique(); // dedupe key for inbound
            $table->string('type', 32)->default('text');
            $table->text('body')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        // Payment links raised from a chat, so the right person hears when one is paid.
        Schema::create('whatsapp_assistant_payment_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('payment_link_id')->unique(); // logical ref to limo_payment_links
            $table->unsignedBigInteger('conversation_id')->index();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_assistant_payment_links');
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_conversations');
        Schema::dropIfExists('whatsapp_assistant_staff');
        Schema::dropIfExists('whatsapp_assistant_configuration');
    }
};
