<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Direct synchronous image upload for FormView image fields. See
 * {@see BaseFileUploadController} for why this bypasses Livewire's async upload.
 */
final class FormImageUploadController extends BaseFileUploadController
{
    /**
     * SVG is deliberately excluded — it can carry inline <script> that runs as
     * stored-XSS when another user opens the file URL. Raster formats only.
     *
     * @return list<string>
     */
    protected function fileRules(Request $request): array
    {
        return ['mimes:jpg,jpeg,png,gif,webp,bmp,avif,heic,heif', 'max:4096'];
    }

    /**
     * Buckets the engine FormView image widget may write to. New
     * `DefinesIrModel` tables that need image uploads must be added here.
     *
     * @return list<string>
     */
    protected function allowedBuckets(): array
    {
        return [
            'pos_products',
            'pos_categories',
            'partners',
            'avatars',
            // Company branding (logo on receipt + login + topbar).
            'company',
            // Rental: customer CPR/licence captured on the order, and deposit
            // evidence photos.
            'rental_orders',
            'rental_deposits',
        ];
    }
}
