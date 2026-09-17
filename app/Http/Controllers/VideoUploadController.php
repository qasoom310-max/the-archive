<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores a video on THIS server (the public disk) instead of Cloudflare Stream,
 * sent in small chunks so a phone video of any length fits under the host's
 * per-request upload limit (PHP's default is 2 MB).
 *
 * The browser sends chunk 0..total-1 in order under one upload id; each is
 * kept in a private per-user folder, and the last one assembles the file,
 * checks it really is a video, and moves it to the public disk under an
 * unguessable name (a customer's car on camera must not be enumerable).
 *
 * Stale chunk folders from abandoned uploads are swept by the daily
 * `prune-video-chunks` task in routes/console.php.
 */
final class VideoUploadController
{
    /** Largest chunk accepted, in bytes. The browser sends 2,000,000. */
    public const CHUNK_BYTES = 2_000_000;

    /** Largest whole video, in bytes (1 GB). */
    public const MAX_BYTES = 1_000_000_000;

    /** Public-disk folder. Sits inside `rental_orders/`, which deploy.yml's rsync already protects. */
    public const DIRECTORY = 'rental_orders/videos';

    /** Private-disk folder for in-progress chunks. */
    public const CHUNK_DIRECTORY = 'video-chunks';

    /** Container → extension for the videos we accept. */
    private const EXTENSIONS = [
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
        'video/x-m4v' => 'm4v',
        'video/webm' => 'webm',
        'video/3gpp' => '3gp',
        'video/3gpp2' => '3g2',
        'video/x-matroska' => 'mkv',
        'video/x-msvideo' => 'avi',
    ];

    public function __invoke(Request $request): JsonResponse
    {
        $maxChunks = (int) ceil(self::MAX_BYTES / self::CHUNK_BYTES);

        $data = $request->validate([
            'upload_id' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{16,64}$/'],
            'index' => ['required', 'integer', 'min:0'],
            'total' => ['required', 'integer', 'min:1', 'max:' . $maxChunks],
            'chunk' => ['required', 'file', 'max:' . (int) ceil(self::CHUNK_BYTES / 1024)],
        ]);

        $index = (int) $data['index'];
        $total = (int) $data['total'];
        if ($index >= $total) {
            return response()->json(['error' => __('The upload was interrupted. Please choose the video again.')], 422);
        }

        /** @var UploadedFile $chunk */
        $chunk = $data['chunk'];
        // Scoped per user, so one account can never add to another's upload.
        $folder = self::CHUNK_DIRECTORY . '/' . (int) Auth::id() . '/' . $data['upload_id'];
        $disk = Storage::disk('local');
        $disk->putFileAs($folder, $chunk, (string) $index);

        if ($index < $total - 1) {
            return response()->json(['received' => $index + 1]);
        }

        try {
            return $this->assemble($folder, $total);
        } finally {
            $disk->deleteDirectory($folder);
        }
    }

    private function assemble(string $folder, int $total): JsonResponse
    {
        $local = Storage::disk('local');
        $assembled = $local->path($folder . '/assembled');

        $out = fopen($assembled, 'wb');
        if ($out === false) {
            return $this->failed();
        }

        $bytes = 0;
        try {
            for ($i = 0; $i < $total; $i++) {
                $part = $folder . '/' . $i;
                if (! $local->exists($part)) {
                    return response()->json(['error' => __('The upload was interrupted. Please choose the video again.')], 422);
                }
                $bytes += (int) $local->size($part);
                if ($bytes > self::MAX_BYTES) {
                    return response()->json(['error' => __('The video is larger than 1 GB.')], 422);
                }
                $in = fopen($local->path($part), 'rb');
                if ($in === false) {
                    return $this->failed();
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
        } finally {
            fclose($out);
        }

        // Judge the file by its contents, never by the name it arrived with.
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($assembled);
        $extension = self::EXTENSIONS[$mime] ?? null;
        if ($extension === null) {
            return response()->json(['error' => __('That file is not a video.')], 422);
        }

        $path = self::DIRECTORY . '/' . now()->format('Y/m') . '/' . Str::random(40) . '.' . $extension;
        $stream = fopen($assembled, 'rb');
        if ($stream === false) {
            return $this->failed();
        }
        $public = Storage::disk('public');
        $stored = $public->writeStream($path, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }
        if ($stored === false) {
            return $this->failed();
        }

        return response()->json(['url' => $public->url($path), 'path' => $path]);
    }

    private function failed(): JsonResponse
    {
        return response()->json(['error' => __('Upload failed. Please try again.')], 500);
    }
}
