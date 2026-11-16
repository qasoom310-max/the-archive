<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Erp\Updates\UpdateLog;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves one before/after picture of a "What's new" entry.
 *
 * The pictures are screenshots of the system, so they sit outside `public/`
 * and come through here, behind sign-in. Only a file an entry lists by name
 * is ever served — the path is never built from the request alone.
 */
final class UpdateImageController extends Controller
{
    public function __invoke(UpdateLog $log, string $key, string $file): BinaryFileResponse
    {
        $path = $log->imagePath($key, $file);
        abort_if($path === null, 404);

        return response()->file($path, ['Cache-Control' => 'private, max-age=86400']);
    }
}
