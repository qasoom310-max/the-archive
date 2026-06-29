<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Pos\Livewire\PosProductGallery;
use Modules\Pos\Models\PosProduct;
use Tests\TestCase;

/**
 * Secondary (gallery) images on a POS product: the embedded editor adds /
 * removes extra photos stored in `pos_products.gallery_images`, on top of the
 * single primary `image_path` the engine form owns. These feed the WooCommerce
 * push (asserted in WooCommerceModuleTest).
 */
final class PosProductGalleryTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('pos');
    }

    private function product(): PosProduct
    {
        return PosProduct::query()->create(['name' => 'Oud Perfume', 'price' => 25, 'tax_rate' => 0, 'active' => true]);
    }

    public function test_admin_can_add_and_remove_secondary_images(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $product = $this->product();

        Livewire::test(PosProductGallery::class, ['productId' => $product->id])
            ->call('addImage', 'pos_products/a.webp')
            ->call('addImage', 'pos_products/b.webp')
            ->call('addImage', 'pos_products/a.webp'); // duplicate ignored

        $this->assertSame(['pos_products/a.webp', 'pos_products/b.webp'], $product->fresh()?->galleryImages());

        Livewire::test(PosProductGallery::class, ['productId' => $product->id])
            ->call('removeImage', 0);

        $this->assertSame(['pos_products/b.webp'], $product->fresh()?->galleryImages());
    }

    public function test_blank_path_is_ignored(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $product = $this->product();

        Livewire::test(PosProductGallery::class, ['productId' => $product->id])
            ->call('addImage', '   ');

        $this->assertSame([], $product->fresh()?->galleryImages());
    }

    public function test_a_user_without_product_access_is_forbidden(): void
    {
        // A plain user with no pos.product ACL (deny-by-default) can't open it.
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $product = $this->product();

        Livewire::test(PosProductGallery::class, ['productId' => $product->id])
            ->assertForbidden();
    }
}
