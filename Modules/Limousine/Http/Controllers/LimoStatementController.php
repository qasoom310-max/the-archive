<?php

declare(strict_types=1);

namespace Modules\Limousine\Http\Controllers;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Services\LimoStatement;

/**
 * Download a customer's statement of account for a date range.
 *
 * Staff-only, like every other document here: the office sends it, the customer
 * does not fetch it. The range comes off the query string so the same link works
 * from the account page and from a saved bookmark.
 */
final class LimoStatementController
{
    public function __invoke(int $customer, Request $request, LimoStatement $statements): Response
    {
        app(AccessControl::class)->authorize(Auth::user(), 'limousine.customer', Permission::Read);

        $model = LimoCustomer::query()->findOrFail($customer);

        $data = $statements->build(
            $model,
            (string) $request->query('from', ''),
            (string) $request->query('to', ''),
        );

        return response($statements->render($data), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $statements->filename($model) . '"',
        ]);
    }
}
