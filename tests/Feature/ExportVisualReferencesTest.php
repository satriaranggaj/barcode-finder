<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductPhoto;
use App\Models\SearchFeedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExportVisualReferencesTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function tearDown(): void
    {
        if (isset($this->directory) && is_dir($this->directory)) {
            collect(new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            ))->each(fn ($file) => $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()));
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    private function freshDirectory(): string
    {
        return $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lensku-export-'.Str::uuid();
    }

    private function addPhoto(Product $product, string $name, ?array $crop = null, ?string $source = 'manual_reference'): ProductPhoto
    {
        Storage::disk('public')->put("products/{$name}", 'bytes-'.$name);

        return $product->photos()->create([
            'path' => "products/{$name}", 'master_path' => "products/{$name}", 'disk' => 'public',
            'crop' => $crop, 'selection_source' => $crop ? 'manual' : 'full',
            'selection_verified' => $crop !== null, 'source' => $source,
            'photo_hash' => hash('sha256', $name),
        ]);
    }

    private function addFeedback(Product $product, string $suffix, bool $eligible = true, string $status = 'verified'): SearchFeedback
    {
        Storage::disk('local')->put("verified-search/q-{$suffix}.webp", 'query-'.$suffix);

        return SearchFeedback::create([
            'user_id' => User::factory()->create()->id, 'confirmed_product_id' => $product->id,
            'predicted_sku' => 'OTHER', 'confirmed_sku' => $product->sku,
            'disk' => 'local', 'query_image_path' => "verified-search/q-{$suffix}.webp",
            'photo_hash' => str_repeat($suffix, 64), 'dhash' => str_repeat('0', 16),
            'crop' => ['x' => 0.1, 'y' => 0.1, 'width' => 0.8, 'height' => 0.8],
            'evidence' => ['capture_group' => 'session-test'],
            'reference_eligible' => $eligible, 'training_status' => $status,
        ]);
    }

    public function test_export_includes_every_photo_with_sidecar_provenance(): void
    {
        Storage::fake('public');
        $product = Product::create(['sku' => 'SKU123', 'description' => 'Obeng plus PH2']);
        $product->attributes()->create(['key' => 'drive', 'value' => 'PH1', 'source' => 'manual']);
        $product->attributes()->create(['key' => 'drive', 'value' => 'PH3', 'source' => 'vision']);
        $front = $this->addPhoto($product, 'front.webp', ['x' => 0.1, 'y' => 0.1, 'width' => 0.8, 'height' => 0.8]);
        $back = $this->addPhoto($product, 'back.webp');
        $legacy = $this->addPhoto($product, 'legacy.webp', null, 'catalog');

        $this->artisan('products:export-visual', ['directory' => $dir = $this->freshDirectory()])
            ->assertSuccessful();

        foreach ([$front, $back, $legacy] as $photo) {
            $image = "{$dir}/SKU123/{$photo->id}.webp";
            $this->assertFileExists($image);
            $this->assertSame('bytes-'.basename($photo->master_path), file_get_contents($image));
            $sidecar = json_decode(file_get_contents("{$dir}/SKU123/{$photo->id}.json"), true);
            $this->assertSame($product->id, $sidecar['product_id']);
            $this->assertSame('Obeng plus PH2', $sidecar['description']);
            $this->assertSame(['drive' => ['PH1']], $sidecar['trusted_attributes']);
            $this->assertSame($photo->photo_hash, $sidecar['source_photo_hash']);
            $this->assertSame('reference-'.$photo->id, $sidecar['capture_group']);
        }
        $this->assertEquals(['x' => 0.1, 'y' => 0.1, 'width' => 0.8, 'height' => 0.8],
            json_decode(file_get_contents("{$dir}/SKU123/{$front->id}.json"), true)['crop']);
        $this->assertSame('manual_reference', json_decode(file_get_contents("{$dir}/SKU123/{$front->id}.json"), true)['source']);
        // Catalog provenance round-trips verbatim, never null.
        $this->assertSame('catalog', json_decode(file_get_contents("{$dir}/SKU123/{$legacy->id}.json"), true)['source']);
        $this->assertFileDoesNotExist("{$dir}/.exporting");
    }

    public function test_verified_only_without_flag(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $product = Product::create(['sku' => 'SKU123']);
        $this->addPhoto($product, 'front.webp');
        $this->addFeedback($product, 'a');

        $this->artisan('products:export-visual', ['directory' => $dir = $this->freshDirectory()])
            ->assertSuccessful();

        $this->assertCount(1, glob("{$dir}/SKU123/*.webp"));
        $this->assertFileDoesNotExist($dir.'/SKU123/verified-1.webp');
    }

    public function test_include_verified_adds_only_eligible_confirmed_search(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $product = Product::create(['sku' => 'SKU123', 'description' => 'Kuas 2 inch']);
        $this->addPhoto($product, 'front.webp');
        $good = $this->addFeedback($product, 'a');
        $this->addFeedback($product, 'b', eligible: false);
        $this->addFeedback($product, 'c', status: 'pending');

        $this->artisan('products:export-visual', ['directory' => $dir = $this->freshDirectory(), '--include-verified' => true])
            ->assertSuccessful();

        $this->assertSame('query-a', file_get_contents("{$dir}/SKU123/verified-{$good->id}.webp"));
        $sidecar = json_decode(file_get_contents("{$dir}/SKU123/verified-{$good->id}.json"), true);
        $this->assertSame('confirmed_search', $sidecar['source']);
        // Parity with incremental builder: selection_source must round-trip
        // (null for legacy rows) or appends see phantom metadata drift.
        $this->assertArrayHasKey('selection_source', $sidecar);
        $this->assertTrue($sidecar['selection_verified']);
        $this->assertSame('Kuas 2 inch', $sidecar['description']);
        $this->assertSame('session-test', $sidecar['capture_group']);
        $this->assertSame($good->photo_hash, $sidecar['source_photo_hash']);
        $this->assertEquals(['x' => 0.1, 'y' => 0.1, 'width' => 0.8, 'height' => 0.8], $sidecar['crop']);
        $this->assertCount(2, glob("{$dir}/SKU123/*.webp"));
    }

    public function test_revoked_or_deleted_reference_is_excluded_from_future_exports(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $product = Product::create(['sku' => 'SKU123']);
        $this->addPhoto($product, 'front.webp');
        $row = $this->addFeedback($product, 'a');

        $this->artisan('products:export-visual', ['directory' => $this->freshDirectory(), '--include-verified' => true])
            ->assertSuccessful();

        // Revoke eligibility: the next generation must not contain it.
        $row->update(['reference_eligible' => false]);
        $this->artisan('products:export-visual', ['directory' => $dir = $this->freshDirectory(), '--include-verified' => true])
            ->assertSuccessful();
        $this->assertCount(1, glob("{$dir}/SKU123/*.webp"));

        // Deletion removes it as well.
        $row->delete();
        $this->artisan('products:export-visual', ['directory' => $dir2 = $this->freshDirectory(), '--include-verified' => true])
            ->assertSuccessful();
        $this->assertCount(1, glob("{$dir2}/SKU123/*.webp"));
    }

    public function test_missing_reference_file_fails_closed(): void
    {
        Storage::fake('public');
        $product = Product::create(['sku' => 'SKU123']);
        // Row points at a file that does not exist on disk.
        $product->photos()->create(['path' => 'products/ghost.webp', 'master_path' => 'products/ghost.webp', 'disk' => 'public']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Missing photo|Export failed/');
        $this->artisan('products:export-visual', ['directory' => $this->freshDirectory()]);
    }
}
