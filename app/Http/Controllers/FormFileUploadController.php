<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Direct synchronous DOCUMENT upload for FormView `file` fields — the document
 * sibling of {@see FormImageUploadController}. Accepts PDF and raster images
 * (an invoice/receipt or a photo); SVG and office/executable formats are
 * excluded to keep the attack surface small. See {@see BaseFileUploadController}.
 */
final class FormFileUploadController extends BaseFileUploadController
{
    /**
     * `only=pdf` (set by a field declaring accept:'pdf') tightens the accepted
     * types to PDF — nothing else gets through, server-side.
     *
     * @return list<string>
     */
    protected function fileRules(Request $request): array
    {
        $mimes = $request->input('only') === 'pdf'
            ? 'mimes:pdf'
            : 'mimes:pdf,jpg,jpeg,png,gif,webp,bmp,avif,heic,heif';

        return [$mimes, 'max:8192'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraRules(Request $request): array
    {
        return ['only' => ['nullable', 'string', Rule::in(['pdf'])]];
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraResponse(UploadedFile $file): array
    {
        return ['name' => $file->getClientOriginalName()];
    }

    /**
     * Buckets the engine FormView `file` widget may write to. The widget derives
     * the bucket from the model's table, so a model opting into a file field
     * adds its table here.
     *
     * @return list<string>
     */
    protected function allowedBuckets(): array
    {
        return [
            'accounts',             // Chart-of-Accounts attachments
            'employee_agreements',  // HR signed agreements
            'rental_vehicles',      // car registration / insurance PDFs
            'rental_customers',     // company CR documents (PDF)
        ];
    }
}
