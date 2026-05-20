<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A sample Chatter-enabled record used by the Phase 3 dashboard demo
 * (until the Contacts module ships its real Partner records in Phase 5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_tickets', function (Blueprint $table): void {
            $table->id();
            $table->string('subject');
            $table->string('stage')->default('New');
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_tickets');
    }
};
