<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Erp\Office\OfficeTokens;
use App\Erp\Settings\CompanyTimezone;
use App\Erp\Tenancy\WorkspaceManager;
use App\Models\OfficeToken;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The AI office's door into the ERP: READ ONLY, by design and by force.
 *
 * 1. Only GET is let through.
 * 2. The key must be a live office key ({@see OfficeTokens}) — never a staff
 *    login, so it carries nobody's permissions.
 * 3. The request names one business database (`X-Workspace`) and the key must
 *    list it. There is no default: a key never lands somewhere by accident.
 * 4. Inside that database the connection is switched to SQLite's
 *    `query_only`, so even a mistake in a read endpoint cannot write.
 *
 * Main, the database, the cache prefix and the timezone are put back after
 * the response, as {@see AppWorkspace} does.
 */
final class OfficeApi
{
    public const HEADER = 'X-Workspace';

    /** Request attribute: the business database being read. */
    public const WORKSPACE = 'office.workspace';

    /** Request attribute: the key used. */
    public const TOKEN = 'office.token';

    public function __construct(
        private readonly OfficeTokens $tokens,
        private readonly WorkspaceManager $workspaces,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return self::error(405, 'The office API is read only.');
        }

        $plain = $request->bearerToken();
        $token = is_string($plain) ? $this->tokens->find($plain) : null;
        if (! $token instanceof OfficeToken) {
            return self::error(401, 'Missing, unknown or revoked office key.');
        }

        $asked = $request->header(self::HEADER);
        $workspace = (is_string($asked) && ctype_digit($asked))
            ? Workspace::query()->find((int) $asked)
            : null;
        if (! $workspace instanceof Workspace || ! $token->mayRead($workspace)) {
            return self::error(403, 'This key may not read that workspace. Send its id in the X-Workspace header.');
        }

        // On Main, before anything turns read-only. At most once a minute.
        if ($token->last_used_at === null || $token->last_used_at->lt(Carbon::now()->subMinute())) {
            $token->forceFill(['last_used_at' => Carbon::now()])->saveQuietly();
        }

        $before = [
            DB::getDefaultConnection(),
            config('database.connections.tenant.database'),
            config('cache.prefix'),
            CompanyTimezone::current(),
        ];

        try {
            if (! $workspace->is_main) {
                $this->workspaces->activate($workspace);
                // activate() stays on Main when the file is missing; never
                // answer with Main's data for another business.
                if (DB::getDefaultConnection() !== 'tenant') {
                    return self::error(503, 'That workspace database is not available.');
                }
            }

            $request->attributes->set(self::WORKSPACE, $workspace);
            $request->attributes->set(self::TOKEN, $token);

            self::readOnly(true);

            try {
                return $next($request);
            } finally {
                self::readOnly(false);
            }
        } finally {
            [$connection, $path, $prefix, $timezone] = $before;
            config([
                'database.default' => $connection,
                'database.connections.tenant.database' => $path,
                'cache.prefix' => $prefix,
            ]);
            DB::setDefaultConnection($connection);
            DB::purge('tenant');
            CompanyTimezone::restore($timezone);
        }
    }

    private static function readOnly(bool $on): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() === 'sqlite') {
            $connection->statement('PRAGMA query_only = ' . ($on ? 'ON' : 'OFF'));
        }
    }

    private static function error(int $status, string $message): JsonResponse
    {
        return response()->json(['error' => $message], $status);
    }
}
