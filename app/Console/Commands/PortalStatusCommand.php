<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Erp\Tenancy\WorkspaceManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Models\LimoPortalConfiguration;

/**
 * Reports whether the Wanaan portal connection (shared by the payment portal
 * AND the pricing API — one HMAC scheme, one secret, per PortalSignature) is
 * configured for a database, WITHOUT ever printing the secret itself.
 *
 * Exists because the Settings → Service Portal secret field is write-only
 * (never echoed back once saved), so there was previously no way to confirm
 * "is a secret already set here" from outside the browser session of
 * whoever originally configured it — needed when handing the SAME secret to
 * a second integration (the pricing API) that reuses it.
 *
 *   php artisan portal:status --workspace=7
 */
final class PortalStatusCommand extends Command
{
    protected $signature = 'portal:status {--workspace= : Workspace id to check (defaults to the current database)}';

    protected $description = 'Report whether the Wanaan portal URL/secret are configured, without revealing the secret.';

    public function handle(WorkspaceManager $workspaces): int
    {
        $option = $this->option('workspace');
        $workspaceId = is_string($option) && $option !== '' ? (int) $option : null;

        if ($workspaceId !== null && $workspaces->findAny($workspaceId) === null) {
            $this->error("Workspace {$workspaceId} does not exist.");

            return self::FAILURE;
        }

        /** @var int $result */
        $result = $workspaces->runFor($workspaceId, fn (): int => $this->report());

        return $result;
    }

    private function report(): int
    {
        if (! Schema::hasTable('limo_portal_configuration')) {
            $this->warn('This database has no limo_portal_configuration table (Limousine module not installed here).');

            return self::SUCCESS;
        }

        $config = LimoPortalConfiguration::current();

        $this->info('Portal URL set: ' . ((string) $config->portal_url !== '' ? 'yes (' . $config->portal_url . ')' : 'no'));
        $this->info('Shared secret set: ' . ((string) $config->shared_secret !== '' ? 'yes' : 'no'));
        $this->info('Payment portal enabled: ' . ($config->enabled ? 'yes' : 'no'));

        $pricingReady = Schema::hasTable('pricing_services');
        $this->info('Pricing tables migrated: ' . ($pricingReady ? 'yes' : 'no'));

        if ($pricingReady) {
            $version = (int) (\App\Erp\Pricing\PricingVersion::current());
            $this->info("Pricing version: {$version}");
        }

        return self::SUCCESS;
    }
}
