<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReferenceSelectionTest extends TestCase
{
    use RefreshDatabase;

    private function fakePrepare(UploadedFile $file): void
    {
        Http::fake([rtrim(config('services.ai.url'), '/').'/prepare' => [
            'status' => 'preserved_memory_budget',
            'extension' => 'jpg',
            'master' => base64_encode(file_get_contents($file->getRealPath())),
        ]]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_upload_with_auto_selection_persists_crop_source_and_verified(): void
    {
        config(['retrieval.driver' => 'faiss']);
        Storage::fake('public');
        Http::preventStrayRequests();
        $product = Product::create(['sku' => 'REF-AUTO']);
        $file = UploadedFile::fake()->image('auto.jpg', 300, 250);
        $this->fakePrepare($file);
        $crop = ['x' => 0.2625, 'y' => 0.19666666666666666, 'width' => 0.4925, 'height' => 0.6183333333333333];
        $this->actingAs($this->admin())
            ->postJson(route('admin.products.photo.store', $product), [
                'images' => [$file],
                'crop_coordinates' => [json_encode($crop)],
                'selection_sources' => ['auto'],
            ])->assertOk();
        $photo = $product->photos()->sole();
        $this->assertSame($crop['x'], $photo->crop['x']);
        $this->assertSame($crop['y'], $photo->crop['y']);
        $this->assertSame($crop['width'], $photo->crop['width']);
        $this->assertSame($crop['height'], $photo->crop['height']);
        $this->assertSame('auto', $photo->selection_source);
        $this->assertTrue($photo->selection_verified);
    }

    public function test_upload_with_manual_override_persists_manual_source(): void
    {
        config(['retrieval.driver' => 'faiss']);
        Storage::fake('public');
        Http::preventStrayRequests();
        $product = Product::create(['sku' => 'REF-MANUAL']);
        $file = UploadedFile::fake()->image('manual.jpg', 300, 250);
        $this->fakePrepare($file);
        $crop = ['x' => 0.1, 'y' => 0.15, 'width' => 0.8, 'height' => 0.7];
        $this->actingAs($this->admin())
            ->postJson(route('admin.products.photo.store', $product), [
                'images' => [$file],
                'crop_coordinates' => [json_encode($crop)],
                'selection_sources' => ['manual'],
            ])->assertOk();
        $photo = $product->photos()->sole();
        $this->assertEquals($crop, $photo->crop);
        $this->assertSame('manual', $photo->selection_source);
        $this->assertTrue($photo->selection_verified);
    }

    public function test_upload_full_image_persists_null_crop_and_full_source(): void
    {
        config(['retrieval.driver' => 'faiss']);
        Storage::fake('public');
        Http::preventStrayRequests();
        $product = Product::create(['sku' => 'REF-FULL']);
        $file = UploadedFile::fake()->image('full.jpg', 300, 250);
        $this->fakePrepare($file);
        $this->actingAs($this->admin())
            ->postJson(route('admin.products.photo.store', $product), [
                'images' => [$file],
                'crop_coordinates' => [''],
                'selection_sources' => ['full'],
            ])->assertOk();
        $photo = $product->photos()->sole();
        $this->assertNull($photo->crop);
        $this->assertSame('full', $photo->selection_source);
        $this->assertFalse($photo->selection_verified);
    }

    public function test_upload_normalizes_source_to_full_when_crop_is_empty(): void
    {
        config(['retrieval.driver' => 'faiss']);
        Storage::fake('public');
        Http::preventStrayRequests();
        $product = Product::create(['sku' => 'REF-STALE']);
        $file = UploadedFile::fake()->image('stale.jpg', 300, 250);
        $this->fakePrepare($file);
        $this->actingAs($this->admin())
            ->postJson(route('admin.products.photo.store', $product), [
                'images' => [$file],
                'crop_coordinates' => [''],
                'selection_sources' => ['auto'],
            ])->assertOk();
        $photo = $product->photos()->sole();
        $this->assertNull($photo->crop);
        $this->assertSame('full', $photo->selection_source);
    }

    public function test_reset_to_auto_via_update_endpoint(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        config(['retrieval.driver' => 'faiss']);
        $product = Product::create(['sku' => 'REF-RESET']);
        $photo = $product->photos()->create([
            'path' => 'products/reset.jpg',
            'crop' => ['x' => 0.1, 'y' => 0.1, 'width' => 0.6, 'height' => 0.6],
            'selection_source' => 'manual',
            'selection_verified' => true,
        ]);
        $auto = ['x' => 0.2625, 'y' => 0.19666666666666666, 'width' => 0.4925, 'height' => 0.6183333333333333];
        $this->actingAs($this->admin())
            ->put(route('admin.photos.selection.update', $photo), [
                'crop_json' => json_encode($auto),
                'selection_source' => 'auto',
            ])->assertRedirect();
        $fresh = $photo->fresh();
        $this->assertSame($auto['x'], $fresh->crop['x']);
        $this->assertSame('auto', $fresh->selection_source);
        $this->assertTrue($fresh->selection_verified);
        $this->assertSame('pending', $fresh->index_status);
    }

    public function test_manual_override_replaces_auto_crop_via_update_endpoint(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        config(['retrieval.driver' => 'faiss']);
        $product = Product::create(['sku' => 'REF-OVERRIDE']);
        $photo = $product->photos()->create([
            'path' => 'products/override.jpg',
            'crop' => ['x' => 0.2625, 'y' => 0.1967, 'width' => 0.4925, 'height' => 0.6183],
            'selection_source' => 'auto',
            'selection_verified' => true,
        ]);
        $manual = ['x' => 0.05, 'y' => 0.05, 'width' => 0.9, 'height' => 0.9];
        $this->actingAs($this->admin())
            ->put(route('admin.photos.selection.update', $photo), [
                'crop_json' => json_encode($manual),
                'selection_source' => 'manual',
            ])->assertRedirect();
        $fresh = $photo->fresh();
        $this->assertEquals($manual, $fresh->crop);
        $this->assertSame('manual', $fresh->selection_source);
        $this->assertSame('pending', $fresh->index_status);
    }

    public function test_full_image_via_update_endpoint_clears_crop(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        config(['retrieval.driver' => 'faiss']);
        $product = Product::create(['sku' => 'REF-CLEAR']);
        $photo = $product->photos()->create([
            'path' => 'products/clear.jpg',
            'crop' => ['x' => 0.1, 'y' => 0.1, 'width' => 0.5, 'height' => 0.5],
            'selection_source' => 'manual',
            'selection_verified' => true,
        ]);
        $this->actingAs($this->admin())
            ->put(route('admin.photos.selection.update', $photo), [
                'crop_json' => '',
                'selection_source' => 'full',
            ])->assertRedirect();
        $fresh = $photo->fresh();
        $this->assertNull($fresh->crop);
        $this->assertSame('full', $fresh->selection_source);
        $this->assertSame('pending', $fresh->index_status);
    }

    public function test_edit_page_carries_stored_crop_and_source(): void
    {
        config(['retrieval.driver' => 'faiss']);
        $product = Product::create(['sku' => 'REF-EDIT-VIEW']);
        $photo = $product->photos()->create([
            'path' => 'products/view.jpg',
            'crop' => ['x' => 0.2, 'y' => 0.2, 'width' => 0.6, 'height' => 0.6],
            'selection_source' => 'auto',
            'selection_verified' => true,
        ]);
        $this->actingAs($this->admin())->get(route('admin.photos.selection.edit', $photo))
            ->assertOk()
            ->assertSee('data-initial-source="auto"', false)
            ->assertSee('data-initial-crop', false);
    }

    public function test_catalog_image_unchanged_by_selection_metadata(): void
    {
        config(['retrieval.driver' => 'faiss']);
        Storage::fake('public');
        Http::preventStrayRequests();
        $product = Product::create(['sku' => 'REF-CATALOG']);
        $file = UploadedFile::fake()->image('catalog.jpg', 400, 300);
        $this->fakePrepare($file);
        $crop = ['x' => 0.2625, 'y' => 0.1967, 'width' => 0.4925, 'height' => 0.6183];
        $this->actingAs($this->admin())
            ->postJson(route('admin.products.photo.store', $product), [
                'images' => [$file],
                'crop_coordinates' => [json_encode($crop)],
                'selection_sources' => ['auto'],
            ])->assertOk();
        $photo = $product->photos()->sole();
        $this->assertSame(hash_file('sha256', $file->getRealPath()), hash_file('sha256', Storage::disk('public')->path($photo->path)));
        // No permanent crop objects: master + coordinates is enough.
        $this->assertSame([$photo->path], Storage::disk('public')->allFiles());
        $filesBefore = Storage::disk('public')->allFiles();
        $this->actingAs($this->admin())
            ->put(route('admin.photos.selection.update', $photo), [
                'crop_json' => json_encode(['x' => 0, 'y' => 0, 'width' => 0.8, 'height' => 0.8]),
                'selection_source' => 'manual',
            ])->assertRedirect();
        $this->assertSame($filesBefore, Storage::disk('public')->allFiles());
        $this->assertSame(hash_file('sha256', $file->getRealPath()), hash_file('sha256', Storage::disk('public')->path($photo->fresh()->path)));
    }
}
