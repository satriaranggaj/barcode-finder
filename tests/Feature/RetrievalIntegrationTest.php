<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\SearchEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RetrievalIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_multi_upload_keeps_separate_crops_and_full_originals(): void
    {
        config(['retrieval.driver' => 'faiss']);
        Storage::fake('public');
        Http::preventStrayRequests();
        $product = Product::create(['sku' => 'CROPS']);
        $files = [UploadedFile::fake()->image('one.jpg', 120, 100), UploadedFile::fake()->image('two.jpg', 140, 100)];
        $hashes = array_map(fn ($file) => hash_file('sha256', $file->getRealPath()), $files);
        $sequence = Http::sequence();
        foreach ($files as $file) {
            $sequence->push(['status' => 'preserved_memory_budget', 'extension' => 'jpg', 'master' => base64_encode(file_get_contents($file->getRealPath()))]);
        }
        Http::fake([rtrim(config('services.ai.url'), '/').'/prepare' => $sequence]);
        $crop = ['x' => .1, 'y' => .2, 'width' => .5, 'height' => .6];
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('admin.products.photo.store', $product), [
                'images' => $files, 'crop_coordinates' => [json_encode($crop), 'null'],
                'selection_sources' => ['manual', 'full'],
            ])->assertOk();
        $photos = $product->photos()->orderBy('id')->get();
        $this->assertCount(2, $photos);
        $this->assertEquals($crop, $photos[0]->crop);
        $this->assertNull($photos[1]->crop);
        foreach ($photos as $i => $photo) {
            $this->assertSame($hashes[$i], hash_file('sha256', Storage::disk('public')->path($photo->path)));
            $this->assertNotEmpty($photo->url);
        }
        Http::assertSentCount(2);
    }

    public function test_prepare_receives_configured_quality_fields(): void
    {
        config([
            'retrieval.driver' => 'faiss',
            'product_images.quality' => 90,
            'product_images.catalog_quality' => 80,
            'product_images.thumbnail_quality' => 75,
            'product_images.catalog_side' => 1400,
            'product_images.thumbnail_side' => 350,
        ]);
        Storage::fake('public');
        Http::preventStrayRequests();
        $file = UploadedFile::fake()->image('quality.jpg', 120, 100);
        Http::fake([rtrim(config('services.ai.url'), '/').'/prepare' => [
            'status' => 'preserved_pixel_limit',
            'extension' => 'jpg',
            'master' => base64_encode(file_get_contents($file->getRealPath())),
        ]]);
        $product = Product::create(['sku' => 'QUALITY-CFG']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('admin.products.photo.store', $product), ['images' => [$file]])
            ->assertOk();
        Http::assertSent(function (Request $request) {
            if (! str_ends_with($request->url(), '/prepare')) {
                return false;
            }
            $fields = $this->multipartFields($request);

            return (string) ($fields['quality'] ?? '') === '90'
                && (string) ($fields['catalog_quality'] ?? '') === '80'
                && (string) ($fields['thumbnail_quality'] ?? '') === '75'
                && (string) ($fields['catalog_side'] ?? '') === '1400'
                && (string) ($fields['thumbnail_side'] ?? '') === '350';
        });
    }

    public function test_invalid_crop_is_rejected_before_processing(): void
    {
        Storage::fake('public');
        Http::preventStrayRequests();
        $product = Product::create(['sku' => 'INVALID']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('admin.products.photo.store', $product), [
                'images' => [UploadedFile::fake()->image('one.jpg')],
                'crop_coordinates' => [json_encode(['x' => .9, 'y' => 0, 'width' => .5, 'height' => 1])],
            ])->assertUnprocessable();
        $this->assertSame(0, $product->photos()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
        Http::assertNothingSent();
    }

    public function test_disabled_feedback_cannot_store_photos(): void
    {
        config(['retrieval.feedback_enabled' => false]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('search.feedback'), [])->assertNotFound();
    }

    public function test_faiss_failure_does_not_fall_back_to_embedding_search(): void
    {
        config(['retrieval.driver' => 'faiss']);
        Http::preventStrayRequests();
        Http::fake([rtrim(config('services.ai.url'), '/').'/search' => Http::response([], 503)]);
        $this->post(route('products.search'), ['image' => UploadedFile::fake()->image('query.jpg')])
            ->assertOk()->assertViewHas('error')->assertViewHas('results', fn ($rows) => $rows->isEmpty());
        Http::assertSentCount(1);
    }

    public function test_faiss_no_match_remains_empty(): void
    {
        config(['retrieval.driver' => 'faiss']);
        Http::preventStrayRequests();
        Http::fake([rtrim(config('services.ai.url'), '/').'/search' => Http::response(['success' => true, 'results' => []])]);
        $this->post(route('products.search'), ['image' => UploadedFile::fake()->image('query.jpg')])
            ->assertOk()->assertViewHas('error', null)->assertViewHas('results', fn ($rows) => $rows->isEmpty());
        Http::assertSentCount(1);
    }

    public function test_failed_evidence_cache_does_not_leave_private_photo(): void
    {
        config(['retrieval.private_disk' => 'local']);
        Storage::fake('local');
        Cache::shouldReceive('put')->once()->andReturn(false);
        $file = UploadedFile::fake()->image('query.jpg');
        $token = app(SearchEvidence::class)->stage([
            'feedback' => ['master' => base64_encode(file_get_contents($file->getRealPath())), 'photo_hash' => str_repeat('a', 64), 'dhash' => str_repeat('0', 16), 'blur' => 100, 'min_side' => 300],
            'results' => [],
        ], 1);
        $this->assertNull($token);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_manual_crop_search_forwards_selection_mode_and_coordinates(): void
    {
        config(['retrieval.driver' => 'faiss']);
        Http::preventStrayRequests();
        Http::fake([rtrim(config('services.ai.url'), '/').'/search' => Http::response(['success' => true, 'results' => []])]);
        $crop = ['x' => .1, 'y' => .2, 'width' => .5, 'height' => .6];
        $this->post(route('products.search'), [
            'image' => UploadedFile::fake()->image('query.jpg'),
            'crop_json' => json_encode($crop),
            'selection_source' => 'manual',
        ])->assertOk()->assertViewHas('error', null);
        Http::assertSent(function ($request) use ($crop) {
            if (! str_ends_with($request->url(), '/search')) {
                return false;
            }
            $fields = $this->multipartFields($request);

            return ($fields['selection_mode'] ?? null) === 'manual'
                && ($fields['preprocessing_mode'] ?? null) === 'original'
                && (string) ($fields['crop_x'] ?? '') === (string) $crop['x']
                && (string) ($fields['crop_y'] ?? '') === (string) $crop['y']
                && (string) ($fields['crop_width'] ?? '') === (string) $crop['width']
                && (string) ($fields['crop_height'] ?? '') === (string) $crop['height'];
        });
    }

    public function test_full_image_search_forwards_selection_mode_without_crop(): void
    {
        config(['retrieval.driver' => 'faiss']);
        Http::preventStrayRequests();
        Http::fake([rtrim(config('services.ai.url'), '/').'/search' => Http::response(['success' => true, 'results' => []])]);
        $this->post(route('products.search'), ['image' => UploadedFile::fake()->image('query.jpg'), 'selection_source' => 'full'])
            ->assertOk()->assertViewHas('error', null);
        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/search')) {
                return false;
            }
            $fields = $this->multipartFields($request);

            return ($fields['selection_mode'] ?? null) === 'full'
                && ($fields['preprocessing_mode'] ?? null) === 'original'
                && ! array_key_exists('crop_x', $fields);
        });
    }

    public function test_default_search_forwards_auto_selection_mode(): void
    {
        config(['retrieval.driver' => 'faiss']);
        Http::preventStrayRequests();
        Http::fake([rtrim(config('services.ai.url'), '/').'/search' => Http::response(['success' => true, 'results' => []])]);
        $this->post(route('products.search'), ['image' => UploadedFile::fake()->image('query.jpg')])
            ->assertOk()->assertViewHas('error', null);
        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/search')) {
                return false;
            }
            $fields = $this->multipartFields($request);

            return ($fields['selection_mode'] ?? null) === 'auto'
                && ($fields['preprocessing_mode'] ?? null) === 'object'
                && ! array_key_exists('crop_x', $fields);
        });
    }

    private function multipartFields(Request $request): array
    {
        $fields = [];
        foreach ($request->data() as $part) {
            if (is_array($part) && isset($part['name'])) {
                $fields[$part['name']] = $part['contents'];
            }
        }

        return $fields;
    }

    public function test_full_image_rejects_crop_with_clear_error(): void
    {
        Http::preventStrayRequests();
        $this->post(route('products.search'), [
            'image' => UploadedFile::fake()->image('query.jpg'),
            'crop_json' => json_encode(['x' => 0, 'y' => 0, 'width' => .5, 'height' => .5]),
            'selection_source' => 'full',
        ])->assertSessionHasErrors('crop_json');
        Http::assertNothingSent();
    }

    public function test_manual_selection_requires_crop(): void
    {
        Http::preventStrayRequests();
        $this->post(route('products.search'), ['image' => UploadedFile::fake()->image('query.jpg'), 'selection_source' => 'manual'])
            ->assertSessionHasErrors('crop');
        Http::assertNothingSent();
    }

    public function test_auto_selection_forwards_the_box_displayed_by_the_ui(): void
    {
        config(['retrieval.driver' => 'faiss']);
        Http::preventStrayRequests();
        Http::fake([rtrim(config('services.ai.url'), '/').'/search' => ['success' => true, 'results' => []]]);
        $this->post(route('products.search'), [
            'image' => UploadedFile::fake()->image('query.jpg'),
            'crop_json' => json_encode(['x' => 0, 'y' => 0, 'width' => .5, 'height' => .5]),
            'selection_source' => 'auto',
        ])->assertOk()->assertViewHas('error', null);
        Http::assertSent(function ($request) {
            $fields = $this->multipartFields($request);

            return $fields['selection_mode'] === 'auto' && (float) $fields['crop_width'] === .5;
        });
    }

    public function test_faiss_is_the_default_search_driver(): void
    {
        $this->assertSame('faiss', config('retrieval.driver'));
    }

    public function test_faiss_search_hydrates_matching_products_without_embed(): void
    {
        Storage::fake('public');
        Http::preventStrayRequests();
        $product = Product::create(['sku' => 'MATCH-1']);
        $photo = $product->photos()->create(['path' => 'products/match.jpg', 'thumbnail_path' => 'thumbs/match.jpg']);
        Http::fake([rtrim(config('services.ai.url'), '/').'/search' => Http::response([
            'success' => true, 'confidence' => 'high', 'relevance_calibrated' => false,
            'results' => [['rank' => 1, 'sku' => 'MATCH-1', 'product_id' => $product->id, 'image_id' => (string) $photo->id, 'score' => 0.91]],
        ])]);
        $response = $this->post(route('products.search'), ['image' => UploadedFile::fake()->image('query.jpg')]);
        $response->assertOk()->assertViewHas('error', null)
            ->assertViewHas('results', fn ($rows) => $rows->count() === 1 && $rows->first()->sku === 'MATCH-1' && abs($rows->first()->similarity - 0.91) < 0.0001);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/search'));
    }

    public function test_sub_threshold_rows_are_hidden_but_prediction_stays_truthful(): void
    {
        Storage::fake('public');
        Http::preventStrayRequests();
        $high = Product::create(['sku' => 'HIGH-1']);
        $highPhoto = $high->photos()->create(['path' => 'products/high.jpg', 'thumbnail_path' => 'thumbs/high.jpg']);
        $low = Product::create(['sku' => 'LOW-1']);
        $low->photos()->create(['path' => 'products/low.jpg', 'thumbnail_path' => 'thumbs/low.jpg']);
        Http::fake([rtrim(config('services.ai.url'), '/').'/search' => Http::response([
            'success' => true, 'confidence' => 'low', 'relevance_calibrated' => false,
            'results' => [
                ['rank' => 1, 'sku' => 'HIGH-1', 'product_id' => $high->id, 'image_id' => (string) $highPhoto->id, 'score' => 0.91],
                ['rank' => 2, 'sku' => 'LOW-1', 'product_id' => $low->id, 'image_id' => 'x', 'score' => 0.35],
            ],
        ])]);
        $response = $this->post(route('products.search'), ['image' => UploadedFile::fake()->image('query.jpg')]);
        $response->assertOk()->assertViewHas('error', null)
            ->assertViewHas('results', fn ($rows) => $rows->count() === 1 && $rows->first()->sku === 'HIGH-1')
            ->assertViewHas('predictedSku', 'HIGH-1');
    }

    public function test_all_filtered_results_show_empty_state_with_hidden_prediction(): void
    {
        Http::preventStrayRequests();
        Product::create(['sku' => 'LOW-1']);
        Http::fake([rtrim(config('services.ai.url'), '/').'/search' => Http::response([
            'success' => true, 'confidence' => 'low', 'relevance_calibrated' => false,
            'results' => [['rank' => 1, 'sku' => 'LOW-1', 'product_id' => 1, 'image_id' => 'x', 'score' => 0.3]],
        ])]);
        $response = $this->post(route('products.search'), ['image' => UploadedFile::fake()->image('query.jpg')]);
        $response->assertOk()->assertViewHas('error', null)
            ->assertViewHas('results', fn ($rows) => $rows->isEmpty())
            ->assertViewHas('predictedSku', 'LOW-1')
            ->assertSee('Tidak ada produk yang cukup mirip');
    }

    public function test_display_threshold_is_configurable(): void
    {
        config(['services.ai.search_min_similarity' => 0.95]);
        Storage::fake('public');
        Http::preventStrayRequests();
        $product = Product::create(['sku' => 'MATCH-1']);
        $product->photos()->create(['path' => 'products/match.jpg', 'thumbnail_path' => 'thumbs/match.jpg']);
        Http::fake([rtrim(config('services.ai.url'), '/').'/search' => Http::response([
            'success' => true, 'confidence' => 'high', 'relevance_calibrated' => false,
            'results' => [['rank' => 1, 'sku' => 'MATCH-1', 'product_id' => $product->id, 'image_id' => '1', 'score' => 0.91]],
        ])]);
        $this->post(route('products.search'), ['image' => UploadedFile::fake()->image('query.jpg')])
            ->assertOk()->assertViewHas('results', fn ($rows) => $rows->isEmpty());
    }

    public function test_malformed_json_response_shows_error_without_legacy_fallback(): void
    {
        Http::preventStrayRequests();
        Http::fake([rtrim(config('services.ai.url'), '/').'/search' => Http::response('not-json{{', 200)]);
        $this->post(route('products.search'), ['image' => UploadedFile::fake()->image('query.jpg')])
            ->assertOk()->assertViewHas('error')->assertViewHas('results', fn ($rows) => $rows->isEmpty());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/search'));
    }

    public function test_connection_refused_shows_friendly_error(): void
    {
        Http::preventStrayRequests();
        Http::fake([rtrim(config('services.ai.url'), '/').'/search' => function () {
            throw new ConnectionException('cURL error 7: Connection refused');
        }]);
        // Any legacy /embed fallback would trip preventStrayRequests and fail the request.
        $this->post(route('products.search'), ['image' => UploadedFile::fake()->image('query.jpg')])
            ->assertOk()->assertViewHas('error')->assertViewHas('results', fn ($rows) => $rows->isEmpty());
    }

    public function test_http_4xx_response_shows_friendly_error(): void
    {
        Http::preventStrayRequests();
        Http::fake([rtrim(config('services.ai.url'), '/').'/search' => Http::response(['detail' => 'bad'], 422)]);
        $this->post(route('products.search'), ['image' => UploadedFile::fake()->image('query.jpg')])
            ->assertOk()->assertViewHas('error')->assertViewHas('results', fn ($rows) => $rows->isEmpty());
        Http::assertSentCount(1);
    }

    public function test_full_contract_candidate_maps_scores_and_ambiguity(): void
    {
        Storage::fake('public');
        Http::preventStrayRequests();
        $product = Product::create(['sku' => 'FULL-1']);
        $photo = $product->photos()->create(['path' => 'products/full.jpg', 'thumbnail_path' => 'thumbs/full.jpg']);
        Http::fake([rtrim(config('services.ai.url'), '/').'/search' => Http::response([
            'success' => true, 'confidence' => 'medium', 'score_gap' => 0.02,
            'confidence_calibrated' => false, 'ambiguous' => true,
            'ambiguity_reason' => 'near_tie', 'relevance_calibrated' => false,
            'ambiguity_alternatives' => [['sku' => 'FULL-1', 'score' => 0.81, 'family' => null]],
            'candidate_images' => 2, 'latency_ms' => 12.5,
            'query' => ['selection_mode' => 'auto'],
            'results' => [[
                'rank' => 1, 'sku' => 'FULL-1', 'product_id' => $product->id,
                'image_id' => (string) $photo->id, 'family' => null, 'sub_category' => null,
                'score' => 0.81, 'visual_score' => 0.8, 'object_score' => 0.8,
                'global_score' => 0.79, 'ocr_score' => 0.9, 'text_score' => null,
                'siglip_score' => 0.78, 'dino_score' => 0.82, 'local_score' => null,
                'matched_images' => 2, 'matched_image_ids' => [(string) $photo->id],
            ]],
        ])]);
        $response = $this->post(route('products.search'), ['image' => UploadedFile::fake()->image('query.jpg')]);
        $response->assertOk()->assertViewHas('error', null)
            ->assertViewHas('results', fn ($rows) => $rows->count() === 1 && abs($rows->first()->similarity - 0.81) < 0.0001)
            ->assertViewHas('ambiguity', fn ($ambiguity) => $ambiguity['ambiguous'] === true
                && $ambiguity['reason'] === 'near_tie'
                && array_column($ambiguity['alternatives'], 'sku') === ['FULL-1']);
        $response->assertSee('Hasil imbang', false);
        Http::assertSentCount(1);
    }

    public function test_embedding_leak_in_search_response_is_rejected(): void
    {
        Http::preventStrayRequests();
        Http::fake([rtrim(config('services.ai.url'), '/').'/search' => Http::response([
            'success' => true, 'results' => [['sku' => 'LEAK-1', 'score' => 0.9, 'embedding' => [0.1, 0.2]]],
        ])]);
        $this->post(route('products.search'), ['image' => UploadedFile::fake()->image('query.jpg')])
            ->assertOk()->assertViewHas('error')->assertViewHas('results', fn ($rows) => $rows->isEmpty());
        Http::assertSentCount(1);
    }

    public function test_read_timeout_shows_error_with_single_attempt(): void
    {
        config(['retrieval.driver' => 'faiss', 'retrieval.feedback_enabled' => true,
            'retrieval.query_temp_disk' => 'local']);
        Storage::fake('local');
        Http::preventStrayRequests();
        $attempts = 0;
        Http::fake([rtrim(config('services.ai.url'), '/').'/search' => function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('cURL error 28: Operation timed out');
        }]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('products.search'), ['image' => UploadedFile::fake()->image('query.jpg'), 'allow_feedback' => '1'])
            ->assertOk()->assertViewHas('error')->assertViewHas('results', fn ($rows) => $rows->isEmpty());
        // No retry on expensive inference calls; no transient file left behind.
        $this->assertSame(1, $attempts);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_contract_violation_is_logged_without_image_data(): void
    {
        Http::preventStrayRequests();
        Log::spy();
        // Empty results are valid; force a violation with an embedding leak instead.
        Http::fake([rtrim(config('services.ai.url'), '/').'/search' => Http::response([
            'success' => true, 'results' => [['sku' => 'X', 'score' => 0.5, 'embedding' => [0.1]]],
        ])]);
        $this->post(route('products.search'), ['image' => UploadedFile::fake()->image('query.jpg')])
            ->assertOk()->assertViewHas('error');
        Log::shouldHaveReceived('warning')->with('AI retrieval contract violation', ['reason' => 'respons /search memuat embedding']);
    }

    public function test_mime_valid_but_corrupt_image_fails_cleanly_at_ai_layer(): void
    {
        // Truncated JPEG: finfo reports image/jpeg so Laravel validation passes;
        // only ai-service content decode can reject it. Must fail gracefully.
        $file = UploadedFile::fake()->image('broken.jpg', 100, 100);
        $bytes = file_get_contents($file->getRealPath());
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'brk-'.uniqid().'.jpg';
        file_put_contents($path, substr($bytes, 0, 30));
        $truncated = new UploadedFile($path, 'broken.jpg', null, null, true);
        Http::preventStrayRequests();
        Http::fake([rtrim(config('services.ai.url'), '/').'/search' => Http::response(['detail' => 'corrupt'], 422)]);
        Storage::fake('public');
        $this->post(route('products.search'), ['image' => $truncated])
            ->assertOk()->assertViewHas('error')->assertViewHas('results', fn ($rows) => $rows->isEmpty());
        Http::assertSentCount(1);
        $this->assertSame(0, Product::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_failed_variant_processing_leaves_no_partial_files(): void
    {
        config(['retrieval.driver' => 'faiss']);
        Storage::fake('public');
        Http::preventStrayRequests();
        $file = UploadedFile::fake()->image('one.jpg', 120, 100);
        Http::fake([rtrim(config('services.ai.url'), '/').'/prepare' => Http::response([
            'status' => 'optimized', 'extension' => 'jpg',
            'master' => base64_encode(file_get_contents($file->getRealPath())),
            'catalog' => '!!!not-base64!!!',
        ])]);
        $product = Product::create(['sku' => 'PARTIAL']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('admin.products.photo.store', $product), ['images' => [$file]])
            ->assertStatus(503);
        $this->assertSame(0, $product->photos()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_search_forwards_top_k_and_preprocessing_contract(): void
    {
        Http::preventStrayRequests();
        Http::fake([rtrim(config('services.ai.url'), '/').'/search' => Http::response(['success' => true, 'results' => []])]);
        $this->post(route('products.search'), ['image' => UploadedFile::fake()->image('query.jpg')])->assertOk();
        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/search')) {
                return false;
            }
            $fields = [];
            foreach ($request->data() as $part) {
                if (is_array($part) && isset($part['name'])) {
                    $fields[$part['name']] = $part['contents'];
                }
            }

            return (string) ($fields['top_k'] ?? '') === '5'
                && ($fields['preprocessing_mode'] ?? null) === 'object'
                && ($fields['feedback_preview'] ?? null) === 'false';
        });
    }

    public function test_http_500_response_shows_friendly_error(): void
    {
        Http::preventStrayRequests();
        Http::fake([rtrim(config('services.ai.url'), '/').'/search' => Http::response(['error' => 'boom'], 500)]);
        $this->post(route('products.search'), ['image' => UploadedFile::fake()->image('query.jpg')])
            ->assertOk()->assertViewHas('error')->assertViewHas('results', fn ($rows) => $rows->isEmpty());
        Http::assertSentCount(1);
    }

    public function test_legacy_driver_keeps_embed_and_cosine_path(): void
    {
        config(['retrieval.driver' => 'legacy']);
        Http::preventStrayRequests();
        $product = Product::create(['sku' => 'LEGACY-1']);
        $product->photos()->create(['path' => 'products/legacy.jpg', 'embedding' => '[0.1, 0.2, 0.3]']);
        Http::fake([rtrim(config('services.ai.url'), '/').'/embed' => Http::response(['embedding' => [0.1, 0.2, 0.3]])]);
        $response = $this->post(route('products.search'), ['image' => UploadedFile::fake()->image('query.jpg')]);
        $response->assertOk()->assertViewHas('error', null)
            ->assertViewHas('results', fn ($rows) => $rows->count() === 1 && $rows->first()->sku === 'LEGACY-1');
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/embed'));
    }
}
