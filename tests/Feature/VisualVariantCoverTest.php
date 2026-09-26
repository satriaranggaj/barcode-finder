<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Best-matching variant cover: one SKU stays one card, but the card cover
 * follows the best-scoring matched catalog reference.
 *
 * SKU score = best reference score (max semantics, no count bias).
 * Display cover = best resolvable ProductPhoto of THIS product, trying
 * winning image_id then matched_image_ids in score order. Verified feedback
 * references may win retrieval but never become public covers.
 */
class VisualVariantCoverTest extends TestCase
{
    use RefreshDatabase;

    private function fakeSearch(array $results): void
    {
        Http::preventStrayRequests();
        Http::fake([rtrim(config('services.ai.url'), '/').'/search' => Http::response([
            'success' => true, 'confidence' => 'high', 'relevance_calibrated' => false,
            'results' => $results,
        ])]);
    }

    /**
     * @return array{product: Product, purple: \App\Models\ProductPhoto, pink: \App\Models\ProductPhoto, yellow: \App\Models\ProductPhoto}
     */
    private function flowerProduct(): array
    {
        config(['retrieval.driver' => 'faiss']);
        Storage::fake('public');
        $product = Product::create(['sku' => 'FLOWER-001', 'description' => 'Flower']);
        // Created first so the legacy "$product->photos->first()" fallback
        // would show purple — the wrong variant for a yellow query.
        $purple = $product->photos()->create(['path' => 'products/purple.webp', 'thumbnail_path' => 'thumbs/purple.webp']);
        $pink = $product->photos()->create(['path' => 'products/pink.webp', 'thumbnail_path' => 'thumbs/pink.webp']);
        $yellow = $product->photos()->create(['path' => 'products/yellow.webp', 'thumbnail_path' => 'thumbs/yellow.webp']);

        return compact('product', 'purple', 'pink', 'yellow');
    }

    public function test_winning_catalog_reference_becomes_cover(): void
    {
        ['purple' => $purple, 'pink' => $pink, 'yellow' => $yellow] = $this->flowerProduct();
        $this->fakeSearch([[
            'rank' => 1, 'sku' => 'FLOWER-001', 'image_id' => $yellow->id.'.webp', 'score' => 0.94,
            'matched_images' => 3,
            'matched_image_ids' => [$yellow->id.'.webp', $pink->id.'.webp', $purple->id.'.webp'],
        ]]);

        $this->post(route('products.search'), ['image' => UploadedFile::fake()->image('query.jpg')])
            ->assertOk()->assertViewHas('error', null)
            ->assertViewHas('results', fn ($rows) => $rows->count() === 1
                && $rows->first()->sku === 'FLOWER-001'
                && $rows->first()->photo === $yellow->path
                && abs($rows->first()->similarity - 0.94) < 0.0001);
    }

    public function test_winning_verified_reference_falls_back_to_best_catalog_reference(): void
    {
        ['purple' => $purple, 'pink' => $pink, 'yellow' => $yellow] = $this->flowerProduct();
        $this->fakeSearch([[
            'rank' => 1, 'sku' => 'FLOWER-001', 'image_id' => 'verified-900.webp', 'score' => 0.95,
            'matched_images' => 4,
            'matched_image_ids' => ['verified-900.webp', $yellow->id.'.webp', $pink->id.'.webp', $purple->id.'.webp'],
        ]]);

        $response = $this->post(route('products.search'), ['image' => UploadedFile::fake()->image('query.jpg')]);
        $response->assertOk()->assertViewHas('error', null)
            ->assertViewHas('results', fn ($rows) => $rows->count() === 1
                && $rows->first()->sku === 'FLOWER-001'
                && $rows->first()->photo === $yellow->path
                && abs($rows->first()->similarity - 0.95) < 0.0001);
        // Private feedback images are never exposed as catalog covers.
        $response->assertDontSee('verified-900', false);
    }

    public function test_verified_id_matching_other_photo_is_not_misparsed(): void
    {
        ['pink' => $pink, 'yellow' => $yellow] = $this->flowerProduct();
        // verified-<pinkId> must NOT resolve to the pink ProductPhoto.
        $this->fakeSearch([[
            'rank' => 1, 'sku' => 'FLOWER-001', 'image_id' => 'verified-'.$pink->id.'.webp', 'score' => 0.95,
            'matched_image_ids' => ['verified-'.$pink->id.'.webp', $yellow->id.'.webp'],
        ]]);

        $this->post(route('products.search'), ['image' => UploadedFile::fake()->image('query.jpg')])
            ->assertOk()
            ->assertViewHas('results', fn ($rows) => $rows->count() === 1 && $rows->first()->photo === $yellow->path);
    }

    public function test_unresolvable_reference_uses_safe_fallback(): void
    {
        ['product' => $product, 'purple' => $purple] = $this->flowerProduct();
        $this->fakeSearch([[
            'rank' => 1, 'sku' => 'FLOWER-001', 'image_id' => 'verified-900.webp', 'score' => 0.91,
            'matched_image_ids' => ['verified-900.webp', 'unknown.webp'],
        ]]);

        $this->post(route('products.search'), ['image' => UploadedFile::fake()->image('query.jpg')])
            ->assertOk()->assertViewHas('error', null)
            ->assertViewHas('results', fn ($rows) => $rows->count() === 1
                && $rows->first()->photo === $product->photos->first()->path
                && $rows->first()->photo === $purple->path);
    }

    public function test_cross_product_photo_is_never_used_as_cover(): void
    {
        config(['retrieval.driver' => 'faiss']);
        Storage::fake('public');
        $productA = Product::create(['sku' => 'SKU-A']);
        $photoA = $productA->photos()->create(['path' => 'products/a.jpg', 'thumbnail_path' => 'thumbs/a.jpg']);
        $productB = Product::create(['sku' => 'SKU-B']);
        $photoB = $productB->photos()->create(['path' => 'products/b.jpg', 'thumbnail_path' => 'thumbs/b.jpg']);

        // Malicious/invalid payload: SKU-A claims SKU-B's photo as its match.
        $this->fakeSearch([[
            'rank' => 1, 'sku' => 'SKU-A', 'image_id' => $photoB->id.'.webp', 'score' => 0.93,
            'matched_image_ids' => [$photoB->id.'.webp', $photoA->id.'.webp'],
        ]]);

        $this->post(route('products.search'), ['image' => UploadedFile::fake()->image('query.jpg')])
            ->assertOk()
            ->assertViewHas('results', fn ($rows) => $rows->count() === 1
                && $rows->first()->photo === $photoA->path);
    }

    public function test_cross_product_winning_reference_without_own_match_falls_back_safely(): void
    {
        config(['retrieval.driver' => 'faiss']);
        Storage::fake('public');
        $productA = Product::create(['sku' => 'SKU-A']);
        $photoA = $productA->photos()->create(['path' => 'products/a.jpg', 'thumbnail_path' => 'thumbs/a.jpg']);
        $productB = Product::create(['sku' => 'SKU-B']);
        $photoB = $productB->photos()->create(['path' => 'products/b.jpg', 'thumbnail_path' => 'thumbs/b.jpg']);

        $this->fakeSearch([[
            'rank' => 1, 'sku' => 'SKU-A', 'image_id' => $photoB->id.'.webp', 'score' => 0.93,
            'matched_image_ids' => [$photoB->id.'.webp'],
        ]]);

        $this->post(route('products.search'), ['image' => UploadedFile::fake()->image('query.jpg')])
            ->assertOk()
            ->assertViewHas('results', fn ($rows) => $rows->count() === 1 && $rows->first()->photo === $photoA->path);
    }

    public function test_yellow_query_produces_single_yellow_card(): void
    {
        ['pink' => $pink, 'purple' => $purple, 'yellow' => $yellow] = $this->flowerProduct();
        $this->fakeSearch([[
            'rank' => 1, 'sku' => 'FLOWER-001', 'image_id' => $yellow->id.'.webp', 'score' => 0.94,
            'matched_images' => 3,
            'matched_image_ids' => [$yellow->id.'.webp', $pink->id.'.webp', $purple->id.'.webp'],
        ]]);
        $this->post(route('products.search'), ['image' => UploadedFile::fake()->image('yellow.jpg')])
            ->assertOk()
            ->assertViewHas('results', fn ($rows) => $rows->count() === 1 && $rows->first()->photo === $yellow->path);
    }

    public function test_pink_query_produces_single_pink_card(): void
    {
        ['pink' => $pink, 'purple' => $purple, 'yellow' => $yellow] = $this->flowerProduct();
        $this->fakeSearch([[
            'rank' => 1, 'sku' => 'FLOWER-001', 'image_id' => $pink->id.'.webp', 'score' => 0.93,
            'matched_images' => 3,
            'matched_image_ids' => [$pink->id.'.webp', $yellow->id.'.webp', $purple->id.'.webp'],
        ]]);
        $this->post(route('products.search'), ['image' => UploadedFile::fake()->image('pink.jpg')])
            ->assertOk()
            ->assertViewHas('results', fn ($rows) => $rows->count() === 1 && $rows->first()->photo === $pink->path);
    }

    public function test_purple_query_produces_single_purple_card(): void
    {
        ['pink' => $pink, 'purple' => $purple, 'yellow' => $yellow] = $this->flowerProduct();
        $this->fakeSearch([[
            'rank' => 1, 'sku' => 'FLOWER-001', 'image_id' => $purple->id.'.webp', 'score' => 0.92,
            'matched_images' => 3,
            'matched_image_ids' => [$purple->id.'.webp', $pink->id.'.webp', $yellow->id.'.webp'],
        ]]);
        $this->post(route('products.search'), ['image' => UploadedFile::fake()->image('purple.jpg')])
            ->assertOk()
            ->assertViewHas('results', fn ($rows) => $rows->count() === 1 && $rows->first()->photo === $purple->path);
    }

    public function test_legacy_response_without_matched_ids_still_resolves(): void
    {
        ['yellow' => $yellow] = $this->flowerProduct();
        // Older ai-service payload: only image_id, no matched_image_ids.
        $this->fakeSearch([[
            'rank' => 1, 'sku' => 'FLOWER-001', 'image_id' => $yellow->id.'.webp', 'score' => 0.9,
        ]]);
        $this->post(route('products.search'), ['image' => UploadedFile::fake()->image('query.jpg')])
            ->assertOk()
            ->assertViewHas('results', fn ($rows) => $rows->count() === 1 && $rows->first()->photo === $yellow->path);
    }

    public function test_legacy_unresolvable_winning_id_still_succeeds_via_fallback(): void
    {
        ['purple' => $purple] = $this->flowerProduct();
        // Unresolvable legacy winning id still succeeds via safe fallback.
        $this->fakeSearch([[
            'rank' => 1, 'sku' => 'FLOWER-001', 'image_id' => 'verified-900.webp', 'score' => 0.9,
        ]]);
        $this->post(route('products.search'), ['image' => UploadedFile::fake()->image('query.jpg')])
            ->assertOk()
            ->assertViewHas('results', fn ($rows) => $rows->count() === 1 && $rows->first()->photo === $purple->path);
    }

    public function test_deep_catalog_match_past_position_25_still_resolves(): void
    {
        ['purple' => $purple, 'yellow' => $yellow] = $this->flowerProduct();
        // 34 non-catalog ghosts then the true catalog hit at position 35:
        // within the bounded contract (≤50) the resolver must scan them all
        // instead of stopping early and falling back to the first photo.
        $ghosts = array_map(fn ($i) => "ghost-{$i}.webp", range(1, 34));
        $ids = [...$ghosts, $yellow->id.'.webp'];
        $this->fakeSearch([[
            'rank' => 1, 'sku' => 'FLOWER-001', 'image_id' => 'verified-900.webp', 'score' => 0.93,
            'matched_images' => count($ids), 'matched_image_ids' => $ids,
        ]]);
        $this->post(route('products.search'), ['image' => UploadedFile::fake()->image('query.jpg')])
            ->assertOk()->assertViewHas('error', null)
            ->assertViewHas('results', fn ($rows) => $rows->count() === 1
                && $rows->first()->photo === $yellow->path
                && $rows->first()->photo !== $purple->path);
    }
}
