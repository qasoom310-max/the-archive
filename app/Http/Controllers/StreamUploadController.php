<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Erp\Stream\CloudflareStreamService;
use App\Erp\Stream\StreamException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Server endpoints backing the browser-side video uploader. The browser asks
 * for a one-time Cloudflare upload URL (so the big file goes straight to
 * Cloudflare, not through this server), uploads to it, then asks for the
 * video's public watch URL once Cloudflare has it.
 *
 * Auth-gated (the routes sit in the `auth` group). Errors are returned as
 * JSON 422 so the uploader can show them inline.
 */
final class StreamUploadController extends Controller
{
    public function uploadUrl(Request $request, CloudflareStreamService $stream): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:200'],
        ]);

        try {
            $upload = $stream->createDirectUpload((string) ($validated['name'] ?? 'video'));
        } catch (StreamException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($upload);
    }

    public function info(string $uid, CloudflareStreamService $stream): JsonResponse
    {
        try {
            return response()->json($stream->videoInfo($uid));
        } catch (StreamException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
}
