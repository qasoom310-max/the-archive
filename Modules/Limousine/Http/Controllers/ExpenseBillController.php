<?php

declare(strict_types=1);

namespace Modules\Limousine\Http\Controllers;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Limousine\Models\LimoExpense;
use Modules\Limousine\Services\BillScanner;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Scanning a bill, and showing the bill an expense was scanned from.
 *
 * The photo is posted straight here (not through Livewire's temporary-upload
 * pipeline, which is unreliable on the host), kept on the private disk and
 * read. What was read is parked for a few minutes under a random token tied
 * to the person who uploaded it; the review screen fetches it by that token,
 * so the figures and the photo's path come from the server and never from the
 * browser. Nothing is saved as an expense here.
 */
final class ExpenseBillController extends Controller
{
    public const CACHE_MINUTES = 30;

    public static function cacheKey(string $token): string
    {
        return 'limo-bill-scan:' . $token;
    }

    public function scan(Request $request, BillScanner $scanner): JsonResponse
    {
        app(AccessControl::class)->authorize(Auth::user(), 'limousine.expense', Permission::Create);

        if (! BillScanner::isAvailable()) {
            return response()->json(['message' => __('Bill scanning is not set up yet. Add the Claude API key in WhatsApp → Assistant settings.')], 409);
        }

        $request->validate([
            // The formats the reader accepts. No SVG, no PDF.
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:12288'],
        ]);

        $file = $request->file('file');
        abort_if($file === null || is_array($file), 422);

        $path = $file->store(BillScanner::FOLDER, BillScanner::DISK);
        abort_if($path === false, 500);

        try {
            $draft = $scanner->scan((string) $file->get(), (string) $file->getMimeType());
        } catch (Throwable $e) {
            // The photo is of no use without a reading; do not leave it behind.
            Storage::disk(BillScanner::DISK)->delete($path);
            Log::warning('Bill scan failed: ' . $e->getMessage());

            return response()->json(['message' => __('The bill could not be read just now. Try again, or type the expense in.')], 502);
        }

        if (! $draft['readable']) {
            Storage::disk(BillScanner::DISK)->delete($path);

            return response()->json(['message' => __('That photo could not be read as a bill. Take it again in good light, with the whole bill in the picture.')], 422);
        }

        $token = Str::random(40);
        Cache::put(self::cacheKey($token), ['user' => Auth::id(), 'path' => $path, 'draft' => $draft], now()->addMinutes(self::CACHE_MINUTES));

        return response()->json(['token' => $token]);
    }

    /** The photo of the bill an expense was scanned from. */
    public function show(int $expense): StreamedResponse
    {
        app(AccessControl::class)->authorize(Auth::user(), 'limousine.expense', Permission::Read);

        $record = LimoExpense::query()->findOrFail($expense);
        $path = (string) $record->getAttribute('bill_path');
        abort_if($path === '' || ! Storage::disk(BillScanner::DISK)->exists($path), 404);

        return Storage::disk(BillScanner::DISK)->response($path, null, ['Cache-Control' => 'private, no-store']);
    }
}
