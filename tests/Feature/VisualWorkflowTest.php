<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SearchFeedback;
use App\Models\User;
use App\Services\SearchEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VisualWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_maintenance_preserves_unsafe_original_and_dry_run_is_read_only(): void
    {
        Storage::fake('public');
        $product = Product::create(['sku' => 'MAINTENANCE']);
        $file = UploadedFile::fake()->image('old.jpg');
        $bytes = file_get_contents($file->getRealPath());
        Storage::disk('public')->put('products/old.jpg', $bytes);
        $photo = $product->photos()->create(['path' => 'products/old.jpg']);
        Http::preventStrayRequests();
        $this->artisan('products:compress-images', ['--dry-run' => true])->assertSuccessful();
        Http::assertNothingSent();
        Http::fake(['*/prepare' => Http::response(['status' => 'preserved_memory_budget', 'extension' => 'jpg', 'master' => base64_encode($bytes)])]);
        $this->artisan('products:compress-images')->assertSuccessful();
        $this->assertSame('products/old.jpg', $photo->fresh()->path);
        $this->assertSame('preserved_memory_budget', $photo->fresh()->optimization_status);
        $this->assertSame(['products/old.jpg'], Storage::disk('public')->allFiles());
    }

    public function test_expired_pending_cleanup_retains_verified_photos(): void
    {
        Storage::fake('local');
        config(['retrieval.private_disk' => 'local']);
        Storage::disk('local')->put('search-pending/expired.webp', 'old');
        Storage::disk('local')->put('search-pending/recent.webp', 'new');
        Storage::disk('local')->put('verified-search/keep.webp', 'verified');
        touch(Storage::disk('local')->path('search-pending/expired.webp'), time() - 1000);
        $this->artisan('search:prune-pending')->assertSuccessful();
        Storage::disk('local')->assertMissing('search-pending/expired.webp');
        Storage::disk('local')->assertExists('search-pending/recent.webp');
        Storage::disk('local')->assertExists('verified-search/keep.webp');
    }

    public function test_search_editor_and_consent_are_available(): void
    {
        config(['retrieval.driver' => 'faiss', 'retrieval.feedback_enabled' => true]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/')
            ->assertOk()->assertSee('data-object-selection', false)->assertSee('allow_feedback');
    }

    public function test_manual_query_crop_and_full_image_are_forwarded(): void
    {
        config(['retrieval.driver' => 'faiss']);
        $body = '';
        Http::fake(function ($request) use (&$body) {
            $body = $request->body();

            return Http::response(['success' => true, 'results' => []]);
        });
        $crop = ['x' => .1, 'y' => .1, 'width' => .5, 'height' => .5];
        $this->post(route('products.search'), ['image' => UploadedFile::fake()->image('a.jpg'), 'crop_json' => json_encode($crop), 'selection_source' => 'manual'])->assertOk();
        $this->assertStringContainsString('name="crop_x"', $body);
        $this->assertStringContainsString('original', $body);
    }

    public function test_existing_crop_can_be_edited_and_cleared_without_changing_catalog(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $product = Product::create(['sku' => 'EDIT-CROP']);
        $photo = $product->photos()->create(['path' => 'products/a.jpg']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('admin.photos.selection.edit', $photo))->assertOk();
        $crop = ['x' => .1, 'y' => .1, 'width' => .8, 'height' => .8];
        $this->put(route('admin.photos.selection.update', $photo), ['crop_json' => json_encode($crop), 'selection_source' => 'manual'])->assertRedirect();
        $this->assertEquals($crop, $photo->fresh()->crop);
        $this->assertSame('products/a.jpg', $photo->fresh()->path);
        $this->put(route('admin.photos.selection.update', $photo), ['crop_json' => 'null', 'selection_source' => 'full'])->assertRedirect();
        $this->assertNull($photo->fresh()->crop);
        $this->assertSame('pending', $photo->fresh()->index_status);
    }

    public function test_confirmed_feedback_is_private_verified_and_exportable(): void
    {
        config(['retrieval.feedback_enabled' => true, 'retrieval.private_disk' => 'local']);
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'RIGHT']);
        $image = UploadedFile::fake()->image('q.jpg', 300, 300);
        $report = ['results' => [['sku' => 'WRONG', 'score' => .7]], 'feedback' => [
            'master' => base64_encode(file_get_contents($image->getRealPath())), 'photo_hash' => str_repeat('a', 64),
            'dhash' => str_repeat('0', 16), 'blur' => 100, 'min_side' => 300,
        ]];
        $token = app(SearchEvidence::class)->stage($report, $user->id);
        $this->actingAs($user)->post(route('search.feedback'), ['token' => $token, 'sku' => 'RIGHT'])->assertRedirect(route('products.show', $product));
        $row = SearchFeedback::sole();
        $this->assertSame('verified', $row->training_status);
        $this->assertTrue($row->reference_eligible);
        Storage::disk('local')->assertExists($row->query_image_path);
        $this->assertSame([], Storage::disk('local')->allFiles('search-pending'));
        $target = Storage::disk('local')->path('training.jsonl');
        $this->artisan('search:export-training', ['output' => $target])->assertSuccessful();
        $data = json_decode(file_get_contents($target), true);
        $this->assertSame('WRONG', $data['hard_negative_sku']);
        $this->assertSame('RIGHT', $data['positive_sku']);
        $duplicate = app(SearchEvidence::class)->stage($report, $user->id);
        $this->post(route('search.feedback'), ['token' => $duplicate, 'sku' => 'RIGHT'])
            ->assertRedirect(route('products.show', $product));
        $this->assertSame(1, SearchFeedback::count());
    }

    public function test_image_variants_use_configured_private_disk(): void
    {
        config(['retrieval.driver' => 'faiss', 'product_images.disk' => 'local']);
        Storage::fake('local');
        $image = UploadedFile::fake()->image('a.jpg');
        $bytes = base64_encode(file_get_contents($image->getRealPath()));
        Http::fake(['*/prepare' => Http::response(['status' => 'optimized', 'extension' => 'jpg', 'master' => $bytes, 'catalog' => $bytes, 'thumbnail' => $bytes])]);
        $product = Product::create(['sku' => 'PRIVATE']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson(route('admin.products.photo.store', $product), ['images' => [$image]])->assertOk();
        $photo = $product->photos()->sole();
        foreach ([$photo->path, $photo->master_path, $photo->thumbnail_path] as $path) {
            Storage::disk('local')->assertExists($path);
        }
        $this->assertNotSame($photo->path, $photo->master_path);
        $this->assertSame('local', $photo->disk);
    }
}
