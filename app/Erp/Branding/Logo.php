<?php

declare(strict_types=1);

namespace App\Erp\Branding;

use App\Erp\Settings\Setting;
use Illuminate\Support\Facades\Storage;

/**
 * Single render path for the `company.logo` brand image. The Setting
 * stores a relative path on the `public` disk (e.g. `company/abc.webp`)
 * which an admin uploads through Settings → General. Three call sites
 * read it — the master layout topbar, the guest layout (login page),
 * and the POS receipt overlay.
 *
 * `url()` is null-safe in two ways:
 *
 *   - Setting unset / empty (fresh install or admin cleared it) → null,
 *     so the call site falls back to text branding ("OpenERP").
 *   - Setting set but the underlying file is missing on disk (e.g. an
 *     errant deploy wiped the bucket, like the avatars regression on
 *     2026-05-24) → null, so we never render a broken-image icon.
 *
 * Same null-on-missing pattern as `User::avatarUrl()` — single source
 * of truth means a future fallback policy lands once.
 */
final class Logo
{
    public static function url(): ?string
    {
        $path = Setting::get('company.logo');

        if (! is_string($path) || $path === '') {
            return null;
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($path)) {
            return null;
        }

        return $disk->url($path);
    }
}
