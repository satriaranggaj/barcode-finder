<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\VisualSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VisualSearchTest extends TestCase
{
    use RefreshDatabase;

    public static function representation(): array
    {
        return ['pipeline' => 'lensku-object-1',
            'model_revision' => '3d74acf9a28c67741b2f4f2ea7635f0aaf6f0268',
            'object_model_sha256' => '309c8469258dda742793dce0ebea8e6dd393174f89934733ecc8b14c76f4ddd8',
            'embedding' => array_merge([1.0], array_fill(0, 511, 0.0)),
            'features' => ['color' => array_merge([1], array_fill(0, 31, 0)),
                'texture' => array_merge([1], array_fill(0, 15, 0)),
                'pattern' => array_merge([1], array_fill(0, 31, 0)), 'shape' => [.2, .4]]];
    }

    private function stubSearch(array $rows): void
    {
        $this->app->instance(VisualSearch::class, new class($rows) extends VisualSearch
        {
            public function __construct(private array $rows) {}

            public function policy(): array
            {
                return ['threshold' => .8];
            }

            public function candidates(array $query): Collection
            {
                return collect($this->rows);
            }
        });
        Http::preventStrayRequests();
        Http::fake(['*/features' => Http::response(self::representation())]);
    }

    public function test_valid_no_match_never_refills_or_calls_legacy(): void
    {
        $product = Product::create(['sku' => 'WRONG']);
        $this->stubSearch([(object) ['product_id' => $product->id, 'path' => 'wrong.jpg', 'similarity' => .7,
            'features' => json_encode(self::representation()['features'])]]);
        $response = $this->post('/search', ['image' => UploadedFile::fake()->image('query.jpg')]);
        $response->assertOk()->assertViewHas('error', null)->assertViewHas('results', fn ($results) => $results->isEmpty())
            ->assertSee('Tidak ditemukan barang yang cukup mirip');
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/embed'));
    }

    public function test_best_matching_design_is_used_and_sku_is_deduplicated(): void
    {
        $product = Product::create(['sku' => 'MATCH']);
        $features = json_encode(self::representation()['features']);
        $this->stubSearch([(object) ['product_id' => $product->id, 'path' => 'first.jpg', 'similarity' => .85, 'features' => $features],
            (object) ['product_id' => $product->id, 'path' => 'second.jpg', 'similarity' => .95, 'features' => $features]]);
        $this->post('/search', ['image' => UploadedFile::fake()->image('query.webp')])
            ->assertViewHas('results', fn ($results) => $results->count() === 1 && $results[0]->photo === 'second.jpg');
    }

    public function test_visual_mismatch_rejects_high_global_candidate(): void
    {
        $features = self::representation()['features'];
        $other = $features;
        foreach (['color', 'texture', 'pattern'] as $key) {
            $other[$key] = array_reverse($features[$key]);
        }
        $other['shape'] = [.9, .5];
        $this->assertLessThan(.8, (new VisualSearch)->score(.99, $features, $other));
    }

    public function test_technical_failure_is_not_no_match(): void
    {
        Http::fake(['*/features' => Http::response([], 503)]);
        $this->post('/search', ['image' => UploadedFile::fake()->image('query.jpg')])
            ->assertViewHas('error', fn ($error) => is_string($error));
    }

    public function test_incompatible_representation_is_rejected(): void
    {
        $response = self::representation();
        $response['pipeline'] = 'incompatible';
        Http::fake(['*/features' => Http::response($response)]);
        $this->post('/search', ['image' => UploadedFile::fake()->image('query.jpg')])->assertViewHas('error', fn ($error) => $error !== null);
    }

    public function test_upload_failure_is_preserved_and_reindex_is_idempotent(): void
    {
        Storage::fake('public');
        Http::fake(['*/features' => Http::response([], 503)]);
        $product = Product::create(['sku' => 'PENDING']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('admin.products.photo.store', $product), ['images' => [UploadedFile::fake()->image('one.jpg')]])
            ->assertSessionHasNoErrors();
        $photo = $product->photos()->firstOrFail();
        Storage::disk('public')->assertExists($photo->path);
        $this->assertDatabaseCount('visual_references', 0);
        Http::swap(new Factory);
        Http::fake(['*/features' => Http::response(self::representation())]);
        $this->artisan('products:reindex-visual')->assertSuccessful();
        $this->assertDatabaseCount('visual_references', 1);
        Http::swap(new Factory);
        Http::fake();
        $this->artisan('products:reindex-visual')->assertSuccessful();
        Http::assertNothingSent();
        $photo->delete();
        $this->assertDatabaseCount('visual_references', 0);
    }

    public function test_non_pgvector_runtime_cannot_load_full_catalog(): void
    {
        $this->expectException(\RuntimeException::class);
        (new VisualSearch)->candidates(self::representation());
    }

    public function test_missing_policy_is_technical_failure_not_an_arbitrary_threshold(): void
    {
        config(['visual_search.policy_path' => storage_path('app/missing-policy-test.json')]);
        $this->expectException(\RuntimeException::class);
        (new VisualSearch)->policy();
    }

    public function test_oversized_post_returns_a_clear_error_before_session_middleware(): void
    {
        $this->call('POST', '/search', [], [], [], ['CONTENT_LENGTH' => 2147483647])
            ->assertStatus(413)->assertSee('Total foto melebihi batas upload server');
    }

    public function test_policy_from_another_pipeline_is_rejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'policy-');
        file_put_contents($path, json_encode(['pipeline' => 'old', 'threshold' => .8, 'positive_queries' => 1, 'negative_queries' => 1]));
        config(['visual_search.policy_path' => $path]);
        try {
            $this->expectException(\RuntimeException::class);
            (new VisualSearch)->policy();
        } finally {
            unlink($path);
        }
    }

    public function test_super_admin_can_upload_multiple_compatible_references(): void
    {
        Storage::fake('public');
        Http::fake(['*/features' => Http::response(self::representation())]);
        $product = Product::create(['sku' => 'SUPER-MULTI']);
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))
            ->post(route('admin.products.photo.store', $product), ['images' => [UploadedFile::fake()->image('one.webp'), UploadedFile::fake()->image('two.jpg')]])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('visual_references', 2);
        $this->assertCount(2, $product->photos);
    }

    public function test_upload_count_and_invalid_images_are_rejected(): void
    {
        $product = Product::create(['sku' => 'LIMIT']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('admin.products.photo.store', $product), ['images' => array_fill(0, 11, UploadedFile::fake()->image('one.jpg'))])
            ->assertSessionHasErrors('images');
        $path = tempnam(sys_get_temp_dir(), 'invalid-photo-');
        file_put_contents($path, 'not an image');
        try {
            $this->post('/search', ['image' => new UploadedFile($path, 'fake.jpg', 'image/jpeg', null, true)])->assertSessionHasErrors('image');
        } finally {
            unlink($path);
        }
    }
}
