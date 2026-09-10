<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Erp\Activity\ActivityLogger;
use App\Erp\Pricing\PricingPortalPing;
use App\Erp\Pricing\PricingVersion;
use App\Erp\Tenancy\WorkspaceManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Forces the published-fares version to bump and the website to re-fetch,
 * even though no fare actually changed.
 *
 * {@see PricingWriter} only bumps the version on a genuine DATA write (a
 * rate, an offer, a setting) — by design, so opening and re-saving an
 * unchanged form doesn't spam the website's cache. But a fix to HOW the
 * payload is COMPUTED (e.g. a change to what makes an offer "live") changes
 * what the SAME stored data produces, without writing anything — nothing
 * bumps the version, so the website's cache (keyed to that version as an
 * ETag) never learns anything changed: a ping still carries the version
 * WordPress already has and gets skipped outright, and even an unconditional
 * fetch would hit the ERP's own conditional-GET handling and come back 304.
 *
 * This is that lever: bump first, then ping with the new version so the
 * website's next fetch is a genuine 200 with the corrected payload.
 *
 *   php artisan pricing:republish --workspace=7
 */
final class RepublishPricingCommand extends Command
{
    protected $signature = 'pricing:republish {--workspace= : Workspace id to republish (defaults to the current database)}';

    protected $description = 'Bump the pricing version and force the website to re-fetch, without changing any fare data.';

    public function __construct(
        private readonly WorkspaceManager $workspaces,
        private readonly ActivityLogger $activity,
        private readonly PricingPortalPing $ping,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $option = $this->option('workspace');
        $workspaceId = is_string($option) && $option !== '' ? (int) $option : null;

        // A mistyped id must FAIL, never silently republish Main's fares —
        // the same rule pricing:seed and portal:status follow.
        if ($workspaceId !== null && $this->workspaces->findAny($workspaceId) === null) {
            $this->error("Workspace {$workspaceId} does not exist.");

            return self::FAILURE;
        }

        /** @var int $result */
        $result = $this->workspaces->runFor($workspaceId, fn (): int => $this->republish());

        return $result;
    }

    private function republish(): int
    {
        if (! Schema::hasTable('pricing_version')) {
            $this->error('Pricing tables are missing here — run the migrations for this database first.');

            return self::FAILURE;
        }

        $version = PricingVersion::bump();

        $this->activity->log(
            'pricing_updated',
            'Pricing republish',
            "v{$version} — republished with no fare change (forces the website to re-fetch a corrected computation)",
        );

        $result = $this->ping->send($version, force: true);

        $this->info("Version is now {$version}.");
        $this->line($result['sent']
            ? "Website confirmed: {$result['message']}"
            : "Website did not confirm: {$result['message']}");

        return $result['sent'] ? self::SUCCESS : self::FAILURE;
    }
}
