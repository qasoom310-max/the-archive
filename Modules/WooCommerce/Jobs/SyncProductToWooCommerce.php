<?php

declare(strict_types=1);

namespace Modules\WooCommerce\Jobs;

use App\Erp\Tenancy\WorkspaceManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;
use Modules\WooCommerce\Exceptions\WooCommerceException;
use Modules\WooCommerce\Services\WooCommerceService;

/**
 * Performs the WooCommerce REST call off the request thread so the UI never
 * blocks on the store.
 *
 * The queue is pinned to the Main database (so a tenant swap never logs anyone
 * out / orphans jobs), which means this job RUNS in Main's context even when it
 * was dispatched from a tenant. So it carries the originating `workspaceId` and
 * re-activates that workspace before pushing — otherwise it would read Main's
 * (wrong) WooCommerce config + products. A non-2xx response throws so the job
 * retries, then lands in `failed_jobs`.
 */
final class SyncProductToWooCommerce implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @param string   $action      'sync' (create-or-update) or 'unpublish' (→ draft)
     * @param int|null $workspaceId the workspace whose DB the product lives in
     */
    public function __construct(
        public readonly int $posProductId,
        public readonly string $action = 'sync',
        public readonly ?int $workspaceId = null,
    ) {
    }

    public function handle(WooCommerceService $service): void
    {
        $result = $this->inWorkspace(fn (): array => $service->pushNow($this->posProductId, $this->action));

        // A real API failure (not a skip) → throw so the job retries.
        if (! $result['ok'] && ! $result['skipped'] && $result['error'] !== null) {
            throw new WooCommerceException($result['error']);
        }
    }

    /**
     * Run the push against the originating workspace's database. Main (or no
     * workspaces feature) runs as-is; a tenant is re-activated for the call.
     *
     * @param  \Closure(): array{ok: bool, skipped: bool, error: ?string}  $callback
     * @return array{ok: bool, skipped: bool, error: ?string}
     */
    private function inWorkspace(\Closure $callback): array
    {
        if ($this->workspaceId === null || ! Schema::hasTable('workspaces')) {
            return $callback();
        }

        $manager = app(WorkspaceManager::class);
        $workspace = $manager->findAny($this->workspaceId);

        if ($workspace === null || $workspace->is_main) {
            return $callback();
        }

        $path = $workspace->databasePath();
        if ($path === null || ! is_file($path)) {
            return $callback();
        }

        /** @var array{ok: bool, skipped: bool, error: ?string} $result */
        $result = $manager->withTenant($path, $callback);

        return $result;
    }

    /** Seconds between retries. */
    public function backoff(): int
    {
        return 30;
    }
}
