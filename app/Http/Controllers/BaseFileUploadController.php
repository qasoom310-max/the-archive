<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Shared base for the direct synchronous upload endpoints used by FormView
 * fields (image and document). It exists because Livewire 3's two-phase async
 * upload silently fails on Hostinger shared hosting; a single multipart POST
 * straight to a controller we own sidesteps that. Subclasses only declare the
 * differences: which buckets are allowed, the `file` validation rules, and any
 * extra request rules / response keys.
 */
abstract class BaseFileUploadController
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => array_merge(['required', 'file'], $this->fileRules($request)),
            // `bucket` is the storage subdirectory; whitelisted to a known list so
            // an attacker can't path-traverse to write into an arbitrary location.
            'bucket' => ['required', 'string', 'max:64', Rule::in($this->allowedBuckets())],
            ...$this->extraRules($request),
        ]);

        /** @var UploadedFile $file */
        $file = $validated['file'];
        $path = $file->store($validated['bucket'], 'public');

        return response()->json([
            'path' => $path,
            'url' => Storage::disk('public')->url((string) $path),
            ...$this->extraResponse($file),
        ]);
    }

    /**
     * Buckets this endpoint may write to. Every entry MUST also be in the rsync
     * `--exclude` list in `.github/workflows/deploy.yml`, or `rsync --delete`
     * wipes the uploads on the next deploy (memory: rsync-delete-wipes-user-uploads).
     *
     * @return list<string>
     */
    abstract protected function allowedBuckets(): array;

    /**
     * Validation rules for the `file` field, beyond the shared `required|file`
     * (i.e. the `mimes:…` and `max:…` constraints).
     *
     * @return list<string>
     */
    abstract protected function fileRules(Request $request): array;

    /**
     * Extra request validation rules merged into the rule set.
     *
     * @return array<string, mixed>
     */
    protected function extraRules(Request $request): array
    {
        return [];
    }

    /**
     * Extra keys merged into the JSON response.
     *
     * @return array<string, mixed>
     */
    protected function extraResponse(UploadedFile $file): array
    {
        return [];
    }
}
