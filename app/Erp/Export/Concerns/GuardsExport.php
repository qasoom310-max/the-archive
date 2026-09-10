<?php

declare(strict_types=1);

namespace App\Erp\Export\Concerns;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Support\Facades\Auth;

/**
 * The two lines every bespoke-list export controller repeats: check Read on
 * the same model key the screen itself guards, and build today's filename.
 * An export must never be a way around the screen's own permission.
 */
trait GuardsExport
{
    private function authorizeExport(string $modelKey): void
    {
        app(AccessControl::class)->authorize(Auth::user(), $modelKey, Permission::Read);
    }

    private function exportFilename(string $base): string
    {
        return $base . '-' . now()->format('Y-m-d');
    }
}
