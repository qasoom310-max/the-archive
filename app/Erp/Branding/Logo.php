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
    /** Logo is cropped to a round badge in the topbar. */
    public const SHAPE_CIRCLE = 'circle';

    /** Logo keeps its uploaded shape (letterboxed — nothing cropped). */
    public const SHAPE_NORMAL = 'normal';

    /**
     * How the logo is framed in the topbar, per database. Anything other than
     * an explicit "circle" means normal, so an unseeded workspace (setting row
     * absent) safely falls back to the uploaded shape.
     */
    public static function shape(): string
    {
        return Setting::get('company.logo_shape') === self::SHAPE_CIRCLE
            ? self::SHAPE_CIRCLE
            : self::SHAPE_NORMAL;
    }

    public static function isCircle(): bool
    {
        return self::shape() === self::SHAPE_CIRCLE;
    }

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
