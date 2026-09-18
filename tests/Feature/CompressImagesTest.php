<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompressImagesTest extends TestCase
{
    use RefreshDatabase;

    private string $prepareUrl;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->prepareUrl = rtrim(config('services.ai.url'), '/').'/prepare';
    }

    private function realJpeg(): string
    {
        $file = UploadedFile::fake()->image('real.jpg', 120, 100);

        return file_get_contents($file->getRealPath());
    }

    private function fakeOptimized(): void
    {
        $bytes = base64_encode($this->realJpeg());
        Http::fake([$this->prepareUrl => [
            'status' => 'optimized', 'extension' => 'jpg',
            'master' => $bytes, 'catalog' => $bytes, 'thumbnail' => $bytes,
            'photo_hash' => str_repeat('a', 64),
        ]]);
    }

    public function test_product_without_photos_never_reaches_ai(): void
    {
        $product = Product::create(['sku' => 'NOPHOTO', 'description' => 'Blind box', 'photo' => null]);

        $this->artisan('products:compress-images')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, \App\Models\ProductPhoto::count());
        $fresh = $product->fresh();
        $this->assertNull($fresh->photo);
        $this->assertSame('NOPHOTO', $fresh->sku);
    }

    public function test_photo_with_empty_path_is_skipped_not_failed(): void
    {
        $product = Product::create(['sku' => 'EMPTY']);
        $photo = $product->photos()->create(['path' => '', 'disk' => 'public']);

        $this->artisan('products:compress-images')
            ->expectsOutputToContain('SKIPPED — no source image')
            ->expectsOutputToContain('Failed: 0')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame('', $photo->fresh()->path);
        $this->assertSame(1, \App\Models\ProductPhoto::count());
    }

    public function test_null_product_photo_with_valid_product_photo_is_processed(): void
    {
        $product = Product::create(['sku' => 'HASPHOTO', 'photo' => null]);
        Storage::disk('public')->put('products/real.jpg', $this->realJpeg());
        $photo = $product->photos()->create(['path' => 'products/real.jpg', 'disk' => 'public']);
        $this->fakeOptimized();

        $this->artisan('products:compress-images')->assertSuccessful();

        $photo = $photo->fresh();
        $this->assertSame('optimized', $photo->optimization_status);
        $this->assertNotSame('products/real.jpg', $photo->path);
        $this->assertNull($product->fresh()->photo);
        $this->assertSame('HASPHOTO', $product->fresh()->sku);
    }

    public function test_empty_file_fails_without_calling_ai(): void
    {
        $product = Product::create(['sku' => 'EMPTYFILE']);
        Storage::disk('public')->put('products/empty.jpg', '');
        $photo = $product->photos()->create(['path' => 'products/empty.jpg', 'disk' => 'public']);

        $this->artisan('products:compress-images')
            ->expectsOutputToContain('Source image is empty')
            ->assertFailed();

        Http::assertNothingSent();
        $this->assertSame('products/empty.jpg', $photo->fresh()->path);
        $this->assertNull($photo->fresh()->optimization_status);
    }

    public function test_ai_422_is_rejection_not_unavailable(): void
    {
        $product = Product::create(['sku' => 'REJECTED']);
        Storage::disk('public')->put('products/bad.jpg', 'not-an-image');
        $product->photos()->create(['path' => 'products/bad.jpg', 'disk' => 'public']);
        Http::fake([$this->prepareUrl => Http::response([], 422)]);

        $this->artisan('products:compress-images')
            ->expectsOutputToContain('tidak valid')
            ->assertFailed();
    }

    public function test_ai_503_is_still_unavailable(): void
    {
        $product = Product::create(['sku' => 'DOWN']);
        Storage::disk('public')->put('products/real.jpg', $this->realJpeg());
        $product->photos()->create(['path' => 'products/real.jpg', 'disk' => 'public']);
        Http::fake([$this->prepareUrl => Http::response([], 503)]);

        $this->artisan('products:compress-images')
            ->expectsOutputToContain('tidak tersedia')
            ->assertFailed();
    }
}
