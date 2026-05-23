<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Direct synchronous image upload for FormView image fields.
 *
 * Why this exists: Livewire 3's built-in `WithFileUploads` trait uses a
 * two-phase async upload (browser → signed `/livewire/upload-file` URL →
 * `storage/app/livewire-tmp/` → save()). On Hostinger shared hosting that
 * pipeline silently fails (likely mod_security blocking the multipart POST,
 * or the proxy mangling the URL signature), leaving the form with a phantom
 * temp-file reference and the user with no working save path.
 *
 * This endpoint sidesteps that entire mechanism: a single multipart POST
 * direct to a controller we own, stored straight into the target bucket on
 * the `public` disk, returns the relative path. The Blade just calls this
 * via `fetch` from Alpine when the user picks a file, and writes the path
 * into a regular Livewire string property — no temp-file dance.
 */
final class FormImageUploadController
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file'   => ['required', 'file', 'mimes:jpg,jpeg,png,gif,webp,bmp,svg,avif,heic,heif', 'max:8192'],
            // `bucket` decides the storage subdirectory (e.g. `pos_products`).
            // Whitelisted to lowercase letters/digits/underscores so an attacker
            // can't path-traverse to write into an arbitrary disk location.
            'bucket' => ['required', 'string', 'max:64', Rule::in(self::allowedBuckets())],
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['file'];
        $path = $file->store($validated['bucket'], 'public');

        return response()->json([
            'path' => $path,
            'url'  => Storage::disk('public')->url((string) $path),
        ]);
    }

    /**
     * Buckets the engine FormView is allowed to write to. New `DefinesIrModel`
     * tables that need image uploads must be added here — keeping this list
     * explicit prevents arbitrary-write CVEs through this endpoint.
     *
     * @return list<string>
     */
    private static function allowedBuckets(): array
    {
        return [
            'pos_products',
            'pos_categories',
            'partners',
            'avatars',
        ];
    }
}
