<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add a configurable Meta template language code to whatsapp_configuration.
 *
 * Background: every WhatsApp template is registered under a Meta-side
 * locale (e.g. `en` for "English", `en_US` for "English (US)", `ar` for
 * "Arabic"). Sending a template under the wrong language code returns
 * `(#132001) Template name does not exist in the translation` and the
 * message never delivers. The earlier hard-coded `en_US` was wrong for
 * accounts that registered their templates under plain `en` — most do.
 *
 * Default `en` matches what Meta picks when a template is created from
 * the English locale option in WhatsApp Manager. Admins flip this in
 * Settings → WhatsApp once their template's actual code is known.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_configuration', function (Blueprint $table): void {
            $table->string('template_language', 16)->default('en')->after('api_version');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_configuration', function (Blueprint $table): void {
            $table->dropColumn('template_language');
        });
    }
};
