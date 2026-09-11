<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductPhoto;
use App\Models\User;
use App\Services\ProductImageOptimizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\Concerns\CreatesProductImages;
use Tests\TestCase;

class ProductImageStorageTest extends TestCase
{
    use CreatesProductImages, RefreshDatabase;

    public function test_upload_compresses_storage_but_embeds_original_once(): void
    {
        Storage::fake('public');
        Http::preventStrayRequests();
        $sentBody = '';
        Http::fake([config('services.ai.url').'/embed' => function ($request) use (&$sentBody) {
            $sentBody = $request->body();

            return Http::response(['embedding' => [0.1, 0.2]]);
        }]);
        $product = Product::create(['sku' => 'COMPRESS']);
        $image = $this->productImage();
        $original = file_get_contents($image->getRealPath());

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('admin.products.photo.store', $product), ['images' => [$image]])
            ->assertRedirect(route('admin.products.show', $product))->assertSessionHasNoErrors();

        $photo = $product->photos()->sole();
        $stored = Storage::disk('public')->path($photo->path);
        $this->assertSame('webp', pathinfo($stored, PATHINFO_EXTENSION));
        $this->assertSame([320, 240], array_slice(getimagesize($stored), 0, 2));
        $this->assertLessThan(strlen($original), filesize($stored));
        $this->assertSame('[0.1,0.2]', $photo->embedding);
        $this->assertNotNull($photo->storage_optimized_at);
        Http::assertSentCount(1);
        $this->assertStringContainsString($original, $sentBody);
        Http::assertSent(fn ($request) => $request->hasFile('image', null, 'photo.jpg'));
    }

    public function test_existing_webp_is_not_recompressed(): void
    {
        $image = $this->productImage('photo.webp');
        $prepared = app(ProductImageOptimizer::class)->prepare($image->getRealPath(), 88);

        $this->assertSame('existing_webp', $prepared->reason);
        $this->assertFalse($prepared->temporary);
        $this->assertSame(file_get_contents($image->getRealPath()), file_get_contents($prepared->file));
    }

    #[TestWith([2, 320, 240])]
    #[TestWith([4, 320, 240])]
    #[TestWith([5, 240, 320])]
    #[TestWith([6, 240, 320])]
    #[TestWith([7, 240, 320])]
    #[TestWith([8, 240, 320])]
    public function test_exif_orientation(int $orientation, int $width, int $height): void
    {
        $image = $this->orientedImage($orientation);
        $prepared = app(ProductImageOptimizer::class)->prepare($image->getRealPath(), 88);
        try {
            $this->assertSame('webp', $prepared->reason);
            $this->assertSame([$width, $height], array_slice(getimagesize($prepared->file), 0, 2));
        } finally {
            $prepared->cleanup();
        }
    }

    public function test_color_profile_is_not_discarded(): void
    {
        $jpeg = $this->productImageBytes();
        $profile = "ICC_PROFILE\0\1\1test-profile";
        $image = UploadedFile::fake()->createWithContent('profile.jpg', substr($jpeg, 0, 2)."\xff\xe2".pack('n', strlen($profile) + 2).$profile.substr($jpeg, 2));

        $prepared = app(ProductImageOptimizer::class)->prepare($image->getRealPath(), 88);

        $this->assertSame('preserved_profile', $prepared->reason);
        $this->assertSame($image->getRealPath(), $prepared->file);
    }

    public function test_pixel_limit_rejects_image_before_ai_or_storage(): void
    {
        Storage::fake('public');
        Http::preventStrayRequests();
        config(['product_images.max_pixels' => 100]);
        $product = Product::create(['sku' => 'LIMIT']);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('admin.products.photo.store', $product), ['images' => [$this->productImage()]])
            ->assertSessionHasErrors('images');

        $this->assertDatabaseCount('product_photos', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
        Http::assertNothingSent();
    }

    public function test_failed_second_image_leaves_no_partial_batch(): void
    {
        Storage::fake('public');
        Http::preventStrayRequests();
        Http::fake([config('services.ai.url').'/embed' => Http::response(['embedding' => [0.1, 0.2]])]);
        config(['product_images.max_pixels' => 80000]);
        $product = Product::create(['sku' => 'ATOMIC']);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('admin.products.photo.store', $product), ['images' => [$this->productImage(), $this->productImage('large.jpg', 400, 400)]])
            ->assertSessionHasErrors('images');

        $this->assertDatabaseCount('product_photos', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
        Http::assertSentCount(1);
    }

    public function test_database_failure_cleans_up_new_files(): void
    {
        Storage::fake('public');
        Http::preventStrayRequests();
        Http::fake([config('services.ai.url').'/embed' => Http::response(['embedding' => [0.1, 0.2]])]);
        $product = Product::create(['sku' => 'DB-FAIL']);
        ProductPhoto::creating(fn () => throw new RuntimeException('Database failure'));
        try {
            $this->actingAs(User::factory()->create(['role' => 'admin']))
                ->post(route('admin.products.photo.store', $product), ['images' => [$this->productImage()]])
                ->assertSessionHasErrors('images');
        } finally {
            ProductPhoto::flushEventListeners();
        }

        $this->assertDatabaseCount('product_photos', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_disabled_optimization_preserves_original(): void
    {
        $image = $this->productImage();
        $prepared = app(ProductImageOptimizer::class)->prepare($image->getRealPath(), 88, false);

        $this->assertSame('disabled', $prepared->reason);
        $this->assertSame($image->getRealPath(), $prepared->file);
    }

    public function test_truncated_image_is_rejected_even_with_readable_dimensions(): void
    {
        $bytes = $this->productImageBytes();
        $image = UploadedFile::fake()->createWithContent('truncated.jpg', substr($bytes, 0, (int) (strlen($bytes) * 0.8)));
        $this->assertIsArray(getimagesize($image->getRealPath()));

        $this->expectException(RuntimeException::class);
        app(ProductImageOptimizer::class)->prepare($image->getRealPath(), 88);
    }

    public function test_memory_budget_is_checked_before_decode(): void
    {
        config(['product_images.memory_budget_mb' => 1]);
        $image = $this->productImage();

        $this->expectExceptionMessage('Dimensi gambar melampaui batas pixel atau anggaran memori.');
        app(ProductImageOptimizer::class)->prepare($image->getRealPath(), 88);
    }

    public function test_transparent_png_keeps_alpha_and_dimensions(): void
    {
        $gd = imagecreatetruecolor(320, 240);
        imagealphablending($gd, false);
        imagesavealpha($gd, true);
        imagefill($gd, 0, 0, imagecolorallocatealpha($gd, 0, 0, 0, 127));
        imagefilledrectangle($gd, 100, 50, 200, 150, imagecolorallocatealpha($gd, 200, 20, 30, 0));
        ob_start();
        imagepng($gd, null, 0);
        $image = UploadedFile::fake()->createWithContent('transparent.png', ob_get_clean());
        unset($gd);

        $prepared = app(ProductImageOptimizer::class)->prepare($image->getRealPath(), 88);
        try {
            $this->assertSame('webp', $prepared->reason);
            $decoded = imagecreatefromwebp($prepared->file);
            $this->assertSame(127, imagecolorsforindex($decoded, imagecolorat($decoded, 0, 0))['alpha']);
            $this->assertSame([320, 240], [imagesx($decoded), imagesy($decoded)]);
            unset($decoded);
        } finally {
            $prepared->cleanup();
        }
    }

    public function test_larger_webp_output_keeps_original(): void
    {
        $image = $this->productImage('gradient.png');
        $prepared = app(ProductImageOptimizer::class)->prepare($image->getRealPath(), 100);
        try {
            $this->assertSame('no_savings', $prepared->reason);
            $this->assertSame($image->getRealPath(), $prepared->file);
            $this->assertFalse($prepared->temporary);
        } finally {
            $prepared->cleanup();
        }
    }

    public function test_optimizer_refuses_a_second_decoder_on_the_same_host(): void
    {
        $image = $this->productImage();
        $lock = fopen(storage_path('framework/product-image-optimizer.lock'), 'c');
        flock($lock, LOCK_EX | LOCK_NB);
        try {
            $this->expectExceptionMessage('Pemrosesan gambar sedang sibuk');
            app(ProductImageOptimizer::class)->prepare($image->getRealPath(), 88);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
