<?php

declare(strict_types=1);

namespace Modules\Limousine\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Limousine\Services\PettyCash;
use Modules\Limousine\Support\PettyCashImporter;

/**
 * Uploads a petty-cash CSV (an export from a previous system, the same shape
 * this screen's own export already prints) and imports it via {@see
 * PettyCashImporter}. Hostinger-safe direct POST; gated like issuing and
 * topping up — an administrator or the supervisor accountant.
 */
final class LimoPettyCashImportController
{
    public function __invoke(Request $request, PettyCashImporter $importer): RedirectResponse
    {
        abort_unless(PettyCash::mayManage(Auth::user()), 403);

        $validated = $request->validate(['file' => ['required', 'file', 'max:16384']]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['file'];

        $result = $importer->import($file->getRealPath());

        return redirect('/app/limousine/petty_cash')->with('toast', __(
            ':imported advances imported, :skipped already on file skipped.',
            ['imported' => $result['imported'], 'skipped' => $result['skipped']],
        ));
    }
}
