<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The assistant's long-term notes: things a staff member asked it to
 * remember ("the KSA corporate prices are…"). The chat itself only keeps its
 * recent turns, so anything worth keeping past that is saved here and read
 * back on every message, on WhatsApp and in the ERP test chat alike.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_assistant_memories')) {
            return;
        }

        Schema::create('whatsapp_assistant_memories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index(); // logical ref to users
            $table->text('text');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_assistant_memories');
    }
};
