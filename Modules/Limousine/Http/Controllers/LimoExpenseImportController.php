<?php

declare(strict_types=1);

namespace Modules\Limousine\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Limousine\Support\ExpenseImporter;

/**
 * Uploads an expenses CSV (an export from a previous system, the same shape
 * this screen's own export already prints) and imports it via {@see
 * ExpenseImporter}. Hostinger-safe direct POST; manager-gated.
 */
final class LimoExpenseImportController
{
    public function __invoke(Request $request, ExpenseImporter $importer): RedirectResponse
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canApproveMaintenance(), 403);

        $validated = $request->validate(['file' => ['required', 'file', 'max:16384']]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['file'];

        $result = $importer->import($file->getRealPath());

        return redirect('/app/limousine/expense')->with('toast', __(
            ':imported expenses imported, :skipped already on file skipped.',
            ['imported' => $result['imported'], 'skipped' => $result['skipped']],
        ));
    }
}
