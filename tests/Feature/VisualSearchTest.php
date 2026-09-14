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
        config(['visual_search.policy_path' => storage_path('app/missing-policy-test.json'),
            'visual_search.threshold' => .8, 'visual_search.min_margin' => .04]);
        $this->app->instance(VisualSearch::class, new class($rows) extends VisualSearch
        {
            public function __construct(private array $rows) {}

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
        $this->stubSearch([(object) ['product_id' => $product->id, 'path' => 'first.jpg', 'similarity' => .949, 'features' => $features],
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

    public function test_missing_policy_uses_config_and_search_still_returns_a_match(): void
    {
        $product = Product::create(['sku' => 'NO-POLICY']);
        $this->stubSearch([(object) ['product_id' => $product->id, 'path' => 'match.jpg', 'similarity' => .9,
            'features' => json_encode(self::representation()['features'])]]);
        $this->assertSame(['pipeline' => 'lensku-object-1', 'threshold' => .8, 'min_margin' => .04], (new VisualSearch)->policy());
        $this->post('/search', ['image' => UploadedFile::fake()->image('query.jpg')])
            ->assertViewHas('error', null)->assertViewHas('results', fn ($results) => $results->count() === 1);
    }

    public function test_oversized_post_returns_a_clear_error_before_session_middleware(): void
    {
        $this->call('POST', '/search', [], [], [], ['CONTENT_LENGTH' => 2147483647])
            ->assertStatus(413)->assertSee('Total foto melebihi batas upload server');
    }

    public function test_invalid_or_incompatible_policy_falls_back_to_config(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'policy-');
        config(['visual_search.policy_path' => $path, 'visual_search.threshold' => .72, 'visual_search.min_margin' => .04]);
        try {
            foreach (['{broken', 'null', '"scalar"', '{"pipeline":"old","threshold":0.99}', '{}'] as $json) {
                file_put_contents($path, $json);
                $this->assertSame(['pipeline' => 'lensku-object-1', 'threshold' => .72, 'min_margin' => .04], (new VisualSearch)->policy());
            }
        } finally {
            unlink($path);
        }
    }

    public function test_policy_only_needs_global_parameters_and_falls_back_per_field(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'policy-');
        config(['visual_search.policy_path' => $path, 'visual_search.threshold' => .72, 'visual_search.min_margin' => .04]);
        try {
            foreach ([
                [['threshold' => '0.81', 'min_margin' => '0.06'], .81, .06],
                [['threshold' => .81], .81, .04],
                [['min_margin' => .06], .72, .06],
                [['threshold' => 'invalid', 'min_margin' => []], .72, .04],
            ] as [$overrides, $threshold, $margin]) {
                file_put_contents($path, json_encode(['pipeline' => 'lensku-object-1'] + $overrides));
                $this->assertSame(['pipeline' => 'lensku-object-1', 'threshold' => $threshold, 'min_margin' => $margin], (new VisualSearch)->policy());
            }
        } finally {
            unlink($path);
        }
    }

    public function test_score_uses_two_strongest_signals_despite_changed_viewpoint(): void
    {
        $query = self::representation()['features'];
        $reference = $query;
        $reference['pattern'] = array_reverse($reference['pattern']);
        $reference['shape'] = [.9, .5];
        $search = new VisualSearch;
        $this->assertEqualsWithDelta(.85, $search->score(.8, $query, $reference), .000001);
        $this->assertSame(.8, $search->score(.8, [], []));
        $this->assertEqualsWithDelta(.85, $search->score(.8, ['color' => [1, 0]], ['color' => [1, 0]]), .000001);
    }

    public function test_close_different_products_are_rejected_without_legacy_fallback(): void
    {
        $first = Product::create(['sku' => 'AMBIGUOUS-A']);
        $second = Product::create(['sku' => 'AMBIGUOUS-B']);
        $features = json_encode(self::representation()['features']);
        $this->stubSearch([(object) ['product_id' => $first->id, 'path' => 'first.jpg', 'similarity' => .95, 'features' => $features],
            (object) ['product_id' => $second->id, 'path' => 'second.jpg', 'similarity' => .94, 'features' => $features]]);
        $this->post('/search', ['image' => UploadedFile::fake()->image('query.jpg')])
            ->assertViewHas('error', null)->assertViewHas('results', fn ($results) => $results->isEmpty())
            ->assertSee('Tidak ditemukan barang yang cukup mirip');
        Http::assertSentCount(1);
    }

    public function test_strong_distinct_match_passes_threshold_and_margin(): void
    {
        $first = Product::create(['sku' => 'STRONG-A']);
        $second = Product::create(['sku' => 'STRONG-B']);
        $features = json_encode(self::representation()['features']);
        $this->stubSearch([(object) ['product_id' => $second->id, 'path' => 'second.jpg', 'similarity' => .70, 'features' => $features],
            (object) ['product_id' => $first->id, 'path' => 'first.jpg', 'similarity' => .95, 'features' => $features]]);
        $this->post('/search', ['image' => UploadedFile::fake()->image('query.jpg')])
            ->assertViewHas('error', null)->assertViewHas('results', fn ($results) => $results->count() === 1 && $results[0]->id === $first->id);
    }

    public function test_empty_retrieval_remains_valid_no_match(): void
    {
        $this->stubSearch([]);
        $this->post('/search', ['image' => UploadedFile::fake()->image('query.jpg')])
            ->assertViewHas('error', null)->assertViewHas('results', fn ($results) => $results->isEmpty());
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
