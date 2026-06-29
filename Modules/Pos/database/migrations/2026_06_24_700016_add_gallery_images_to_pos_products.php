<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Secondary (gallery) images for a product, on top of the single primary
 * `image_path`. Stored as a JSON array of relative paths on the `public`
 * disk (same `pos_products` bucket as the primary photo). Pushed to
 * WooCommerce after the primary so a listing shows several photos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_products', function (Blueprint $table): void {
            $table->json('gallery_images')->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('pos_products', function (Blueprint $table): void {
            $table->dropColumn('gallery_images');
        });
    }
};
