<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Direct synchronous DOCUMENT upload for FormView `file` fields — the
 * document sibling of {@see FormImageUploadController}. Same rationale
 * (sidesteps Livewire's two-phase async upload, which fails on Hostinger
 * shared hosting): a single multipart POST straight to a controller we own,
 * stored on the `public` disk, returns the relative path + original name.
 *
 * Accepts PDF and raster images (an invoice/receipt or a photo). SVG is
 * deliberately excluded — it can carry inline <script> that runs as
 * stored-XSS when another user opens the file URL. No office/executable
 * formats either, keeping the attack surface small.
 */
final class FormFileUploadController
{
    public function __invoke(Request $request): JsonResponse
    {
        // `only=pdf` (set by a field declaring accept:'pdf') tightens the
        // accepted types to PDF — nothing else gets through, server-side.
        $mimes = $request->input('only') === 'pdf'
            ? 'mimes:pdf'
            : 'mimes:pdf,jpg,jpeg,png,gif,webp,bmp,avif,heic,heif';

        $validated = $request->validate([
            'file'   => ['required', 'file', $mimes, 'max:8192'],
            // `bucket` decides the storage subdirectory. Whitelisted so an
            // attacker can't path-traverse to write into an arbitrary disk
            // location (same guard as the image controller).
            'bucket' => ['required', 'string', 'max:64', Rule::in(self::allowedBuckets())],
            'only'   => ['nullable', 'string', Rule::in(['pdf'])],
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['file'];
        $path = $file->store($validated['bucket'], 'public');

        return response()->json([
            'path' => $path,
            'url'  => Storage::disk('public')->url((string) $path),
            'name' => $file->getClientOriginalName(),
        ]);
    }

    /**
     * Buckets the engine FormView `file` widget may write to. Every entry
     * here MUST also be in the rsync `--exclude` list in
     * `.github/workflows/deploy.yml`, or `rsync --delete` wipes the
     * uploaded files on the next deploy (memory:
     * rsync-delete-wipes-user-uploads).
     *
     * @return list<string>
     */
    private static function allowedBuckets(): array
    {
        return [
            // `accounts` = the Chart-of-Accounts table name; the `file` widget
            // derives the bucket from the model's table (same as the image
            // widget), so a model opting into a file field adds its table here.
            'accounts',
            // Employee signed-agreement uploads (HR). Also excluded from the
            // deploy rsync --delete so they survive deploys.
            'employee_agreements',
            // Car registration / insurance PDFs (Rental). PDF-only via the
            // field's accept:'pdf'. Excluded from the deploy rsync --delete.
            'rental_vehicles',
            // Company CR documents (PDF) on the customer record.
            'rental_customers',
        ];
    }
}
