<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductPhoto;
use App\Models\SearchFeedback;
use App\Models\User;
use App\Services\SearchEvidence;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Mockery;
use Tests\TestCase;

class StorageArchitectureTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_upload_uses_server_generated_paths_on_local_disk(): void
    {
        config(['retrieval.driver' => 'faiss', 'product_images.disk' => 'public']);
        Storage::fake('public');
        Http::preventStrayRequests();
        $file = UploadedFile::fake()->image('customer-supplied-name.jpg', 120, 100);
        Http::fake([rtrim(config('services.ai.url'), '/').'/prepare' => [
            'status' => 'preserved_pixel_limit',
            'extension' => 'jpg',
            'master' => base64_encode(file_get_contents($file->getRealPath())),
        ]]);
        $product = Product::create(['sku' => 'GENERATED']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('admin.products.photo.store', $product), ['images' => [$file]])
            ->assertOk();
        $photo = $product->photos()->sole();
        $this->assertMatchesRegularExpression(
            '#^products/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}-master\.jpg$#',
            $photo->master_path
        );
        $this->assertStringNotContainsString('customer-supplied-name', $photo->master_path);
        Storage::disk('public')->assertExists($photo->master_path);
    }

    public function test_catalog_variants_land_on_fake_s3_disk(): void
    {
        config(['retrieval.driver' => 'faiss', 'product_images.disk' => 's3']);
        Storage::fake('s3');
        Http::preventStrayRequests();
        $file = UploadedFile::fake()->image('a.jpg', 120, 100);
        $bytes = base64_encode(file_get_contents($file->getRealPath()));
        Http::fake([rtrim(config('services.ai.url'), '/').'/prepare' => Http::response([
            'status' => 'optimized', 'extension' => 'jpg', 'master' => $bytes, 'catalog' => $bytes, 'thumbnail' => $bytes,
        ])]);
        $product = Product::create(['sku' => 'S3-CATALOG']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('admin.products.photo.store', $product), ['images' => [$file]])
            ->assertOk();
        $photo = $product->photos()->sole();
        $this->assertSame('s3', $photo->disk);
        foreach ([$photo->path, $photo->master_path, $photo->thumbnail_path] as $path) {
            Storage::disk('s3')->assertExists($path);
        }
        $this->assertNotSame($photo->path, $photo->master_path);
        $this->assertNotSame($photo->path, $photo->thumbnail_path);
    }

    public function test_catalog_visibility_is_public_only_on_public_disk(): void
    {
        config(['retrieval.driver' => 'faiss']);
        Http::preventStrayRequests();
        $file = UploadedFile::fake()->image('a.jpg', 120, 100);
        $bytes = base64_encode(file_get_contents($file->getRealPath()));
        Http::fake([rtrim(config('services.ai.url'), '/').'/prepare' => [
            'status' => 'preserved_pixel_limit', 'extension' => 'jpg', 'master' => $bytes,
        ]]);
        $storedPaths = [];
        $expectPut = function (array $options) use (&$storedPaths): void {
            $disk = Mockery::mock(FilesystemAdapter::class);
            $disk->shouldReceive('put')->once()
                ->with(Mockery::pattern('#^products/[0-9a-f-]{36}-master\.jpg$#'), Mockery::type('string'), $options)
                ->andReturnUsing(function ($path) use (&$storedPaths) {
                    $storedPaths[] = $path;

                    return true;
                });
            Storage::set(config('product_images.disk'), $disk);
        };
        $product = Product::create(['sku' => 'VISIBILITY']);

        config(['product_images.disk' => 's3']);
        $expectPut(['visibility' => 'private']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('admin.products.photo.store', $product), ['images' => [$file]])->assertOk();

        config(['product_images.disk' => 'public']);
        $expectPut(['visibility' => 'public']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('admin.products.photo.store', $product), ['images' => [$file]])->assertOk();

        $this->assertCount(2, $storedPaths);
    }

    public function test_query_images_stage_on_local_temp_disk_not_object_storage(): void
    {
        config(['retrieval.query_temp_disk' => 'local', 'retrieval.private_disk' => 's3']);
        Storage::fake('local');
        Storage::fake('s3');
        $user = User::factory()->create();
        $image = UploadedFile::fake()->image('q.jpg', 200, 200);
        $token = app(SearchEvidence::class)->stage([
            'results' => [],
            'feedback' => [
                'master' => base64_encode(file_get_contents($image->getRealPath())),
                'photo_hash' => str_repeat('a', 64), 'dhash' => str_repeat('0', 16),
                'blur' => 100, 'min_side' => 200,
            ],
        ], $user->id);
        $this->assertNotNull($token);
        $staged = Storage::disk('local')->allFiles('search-pending');
        $this->assertCount(1, $staged);
        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    public function test_staged_query_image_is_written_with_private_visibility(): void
    {
        config(['retrieval.query_temp_disk' => 'local', 'retrieval.private_disk' => 's3']);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()
            ->with(Mockery::pattern('#^search-pending/[0-9a-f-]{36}\.webp$#'), Mockery::type('string'), ['visibility' => 'private'])
            ->andReturn(true);
        $disk->shouldReceive('delete')->zeroOrMoreTimes();
        Storage::set('local', $disk);
        $user = User::factory()->create();
        $image = UploadedFile::fake()->image('q.jpg', 200, 200);
        $token = app(SearchEvidence::class)->stage([
            'results' => [],
            'feedback' => [
                'master' => base64_encode(file_get_contents($image->getRealPath())),
                'photo_hash' => str_repeat('a', 64), 'dhash' => str_repeat('0', 16),
                'blur' => 100, 'min_side' => 200,
            ],
        ], $user->id);
        $this->assertNotNull($token);
    }

    public function test_confirmed_feedback_moves_to_object_storage_and_cleans_temp(): void
    {
        config(['retrieval.feedback_enabled' => true, 'retrieval.query_temp_disk' => 'local', 'retrieval.private_disk' => 's3']);
        Storage::fake('local');
        $target = Mockery::mock(FilesystemAdapter::class);
        $target->shouldReceive('writeStream')->once()
            ->with(Mockery::pattern('#^verified-search/[0-9a-f-]{36}\.webp$#'), Mockery::type('resource'), ['visibility' => 'private'])
            ->andReturn(true);
        $target->shouldReceive('delete')->zeroOrMoreTimes();
        Storage::set('s3', $target);
        $user = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'RIGHT']);
        $image = UploadedFile::fake()->image('q.jpg', 300, 300);
        $token = app(SearchEvidence::class)->stage([
            'results' => [['sku' => 'WRONG', 'score' => .7]],
            'feedback' => [
                'master' => base64_encode(file_get_contents($image->getRealPath())),
                'photo_hash' => str_repeat('b', 64), 'dhash' => str_repeat('0', 16),
                'blur' => 100, 'min_side' => 300,
            ],
        ], $user->id);
        $this->actingAs($user)->post(route('search.feedback'), ['token' => $token, 'sku' => 'RIGHT'])->assertRedirect();
        $row = SearchFeedback::sole();
        $this->assertSame('s3', $row->disk);
        $this->assertMatchesRegularExpression('#^verified-search/[0-9a-f-]{36}\.webp$#', $row->query_image_path);
        $this->assertSame([], Storage::disk('local')->allFiles('search-pending'));
    }

    public function test_prune_continues_when_one_delete_fails(): void
    {
        config(['retrieval.query_temp_disk' => 'local']);
        $adapter = Mockery::mock(LocalFilesystemAdapter::class);
        $adapter->shouldReceive('listContents')->with('search-pending', false)->andReturn([
            new FileAttributes('search-pending/expired.webp', null, null, time() - 1000),
            new FileAttributes('search-pending/recent.webp', null, null, time()),
        ]);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('getDriver')->andReturn($adapter);
        $disk->shouldReceive('delete')->once()->with('search-pending/expired.webp')
            ->andThrow(new \RuntimeException('object storage down'));
        Storage::set('local', $disk);
        $this->artisan('search:prune-pending')->assertSuccessful();
    }

    public function test_failed_verified_write_cleans_partial_target_and_closes_stream(): void
    {
        config(['retrieval.feedback_enabled' => true, 'retrieval.query_temp_disk' => 'local', 'retrieval.private_disk' => 's3']);
        Storage::fake('local');
        $target = Mockery::mock(FilesystemAdapter::class);
        $captured = null;
        $target->shouldReceive('writeStream')->once()->andReturnUsing(function ($path, $stream) use (&$captured) {
            $captured = $stream;
            throw new \RuntimeException('simulated partial object write');
        });
        $target->shouldReceive('delete')->once()->with(Mockery::pattern('#^verified-search/#'))->andReturn(true);
        Storage::set('s3', $target);
        $user = User::factory()->create(['role' => 'admin']);
        Product::create(['sku' => 'RIGHT']);
        $image = UploadedFile::fake()->image('q.jpg', 300, 300);
        $token = app(SearchEvidence::class)->stage([
            'results' => [], 'feedback' => [
                'master' => base64_encode(file_get_contents($image->getRealPath())),
                'photo_hash' => str_repeat('c', 64), 'dhash' => str_repeat('0', 16),
                'blur' => 100, 'min_side' => 300,
            ],
        ], $user->id);
        $this->actingAs($user)->post(route('search.feedback'), ['token' => $token, 'sku' => 'RIGHT'])->assertServerError();
        $this->assertFalse(is_resource($captured));
        $this->assertDatabaseCount('search_feedback', 0);
        $this->assertCount(1, Storage::disk('local')->allFiles('search-pending'));
    }

    public function test_delete_photo_removes_row_even_when_file_removal_fails(): void
    {
        Storage::fake('public');
        $product = Product::create(['sku' => 'FAILSAFE']);
        $photo = $product->photos()->create(['disk' => 'public', 'path' => 'products/a.jpg']);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('delete')->once()->andThrow(new \RuntimeException('object storage down'));
        Storage::set('public', $disk);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->delete(route('admin.photos.destroy', $photo))
            ->assertRedirect(route('admin.products.show', $product));
        $this->assertModelMissing($photo);
    }

    public function test_selection_image_returns_404_when_file_is_missing(): void
    {
        Storage::fake('public');
        $product = Product::create(['sku' => 'MISSING']);
        $photo = $product->photos()->create(['disk' => 'public', 'path' => 'products/never-stored.jpg']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.photos.selection.image', $photo))
            ->assertNotFound();
    }

    public function test_public_disk_urls_are_public_and_private_disks_are_signed(): void
    {
        $public = ProductPhoto::make(['disk' => 'public', 'path' => 'products/a.jpg']);
        $this->assertSame(config('filesystems.disks.public.url').'/products/a.jpg', $public->url);
        $signed = Mockery::mock(FilesystemAdapter::class);
        $signed->shouldReceive('temporaryUrl')->once()
            ->with('products/a.jpg', Mockery::type(\DateTimeInterface::class))
            ->andReturn('https://signed.example/a.jpg');
        Storage::set('s3', $signed);
        $private = ProductPhoto::make(['disk' => 's3', 'path' => 'products/a.jpg']);
        $this->assertSame('https://signed.example/a.jpg', $private->url);
    }

    public function test_default_disk_configuration_matches_target_layout(): void
    {
        $this->assertSame('public', config('product_images.disk'));
        $this->assertSame('local', config('retrieval.private_disk'));
        $this->assertSame('local', config('retrieval.query_temp_disk'));
        $this->assertSame('local', config('filesystems.default'));
    }
}
