<?php

declare(strict_types=1);

use App\Erp\Pricing\PricingPortalPing;
use App\Erp\Pricing\PricingWriter;
use App\Models\Pricing\PricingSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The owner asked for the website's WhatsApp back on +973 17474949 (2026-10-13),
 * undoing 2026_10_12_100001. The
 * fare widget reads it from the pricing settings, so it is changed there and
 * the website is told to fetch again.
 *
 * Only a database that already publishes a WhatsApp number (Wanaan) is
 * touched; an empty one stays empty. Same path as the Fares screen, so the
 * version bumps once and the change is in the activity log.
 */
return new class extends Migration
{
    private const NUMBER = '97317474949';

    public function up(): void
    {
        if (! Schema::hasTable('pricing_settings') || ! Schema::hasTable('pricing_version')) {
            return;
        }

        $current = DB::table('pricing_settings')->value('whatsapp');
        if (! is_string($current) || $current === '' || $current === self::NUMBER) {
            return;
        }

        $writer = app(PricingWriter::class);
        $version = $writer->transaction('Website settings', static function () use ($writer): array {
            $line = $writer->updateSettings(['whatsapp' => self::NUMBER]);

            return $line === null ? [] : [$line];
        });

        try {
            app(PricingPortalPing::class)->send($version, force: true);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function down(): void
    {
        // Data only.
    }
};
