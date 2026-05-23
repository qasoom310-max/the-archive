<?php

declare(strict_types=1);

/*
 * Minimal Livewire override. Defaults live in the package; we only redeclare
 * what we change. Right now: extend `preview_mimes` so modern image formats
 * (AVIF, plus HEIC/HEIF from iPhones — though only AVIF renders inline in a
 * browser) don't make `TemporaryUploadedFile::temporaryUrl()` throw a
 * FileNotPreviewableException (→ 500) when the user picks one in a form.
 */
return [
    'temporary_file_upload' => [
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
            'avif', 'heic', 'heif',
        ],
    ],
];
