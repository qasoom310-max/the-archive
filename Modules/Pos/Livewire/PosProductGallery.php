<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Modules\Pos\Models\PosProduct;

/**
 * Secondary (gallery) image editor, embedded on the product page beneath the
 * engine form + recipe + condiment editors. The engine form already owns the
 * single primary photo (`image_path`); this manages the EXTRA photos stored
 * in `pos_products.gallery_images` (a JSON path list).
 *
 * Uploads reuse the same FormImageUploadController + `pos_products` bucket as
 * the primary widget — the Blade POSTs the file straight to that controller
 * and calls {@see addImage()} with the returned relative path, so no Livewire
 * temp-file dance (the Hostinger pitfall the controller exists to dodge).
 *
 * Pushed to WooCommerce after the primary image, so a store listing shows the
 * full gallery. Admin-only in practice: `pos.product` Write gates every
 * mutation and cashiers have no `pos.product` access at all.
 */
final class PosProductGallery extends Component
{
    public int $productId;

    public function mount(int $productId): void
    {
        $this->guard(Permission::Read);
        $this->productId = $productId;
    }

    private function guard(Permission $permission): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.product', $permission);
    }

    /** Append an uploaded secondary image path (called by the Alpine uploader). */
    public function addImage(string $path): void
    {
        $this->guard(Permission::Write);

        $path = trim($path);
        if ($path === '') {
            return;
        }

        $product = PosProduct::query()->find($this->productId);
        if ($product === null) {
            return;
        }

        $gallery = $product->galleryImages();
        // De-dupe — the same file picked twice shouldn't list twice.
        if (! in_array($path, $gallery, true)) {
            $gallery[] = $path;
            $product->gallery_images = $gallery;
            $product->save();
        }
    }

    /** Remove the secondary image at $index (DB row only — file stays on disk). */
    public function removeImage(int $index): void
    {
        $this->guard(Permission::Write);

        $product = PosProduct::query()->find($this->productId);
        if ($product === null) {
            return;
        }

        $gallery = $product->galleryImages();
        if (! array_key_exists($index, $gallery)) {
            return;
        }

        unset($gallery[$index]);
        $product->gallery_images = array_values($gallery);
        $product->save();
    }

    public function render(): View
    {
        $product = PosProduct::query()->findOrFail($this->productId);

        return view('pos::product-gallery', [
            'images' => $product->galleryImages(),
            'bucket' => $product->getTable(),
            'canManage' => app(AccessControl::class)->allows(Auth::user(), 'pos.product', Permission::Write),
        ]);
    }
}
