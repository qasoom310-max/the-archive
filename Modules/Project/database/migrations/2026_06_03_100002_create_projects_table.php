<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();

            // Translatable (Spatie HasTranslations) — stored as a JSON
            // envelope {"en":"...","ar":"..."}. TEXT (not VARCHAR) so a
            // multi-locale envelope can't be truncated; SQLite ignores the
            // type but behaves identically.
            $table->text('name');

            $table->text('description')->nullable();

            // Hex colour for visual grouping of the project on boards/lists.
            $table->string('color', 32)->nullable();

            // Analytic link — nullable FK to the minimal analytic ledger.
            // Null on delete so removing an analytic account never orphans
            // a project row.
            $table->foreignId('analytic_account_id')->nullable()
                ->constrained('analytic_accounts')->nullOnDelete();

            // active | archived — see Modules\Project\Enums\ProjectStatus.
            $table->string('status', 16)->default('active')->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
