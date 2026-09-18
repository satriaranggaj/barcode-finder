<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\SearchFeedback;
use App\Models\User;
use App\Services\DuplicateGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DuplicateGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_hamming_distance_counts_bits(): void
    {
        $this->assertSame(0, DuplicateGuard::hammingDistance(str_repeat('0', 16), str_repeat('0', 16)));
        $this->assertSame(1, DuplicateGuard::hammingDistance('00'.str_repeat('0', 14), '01'.str_repeat('0', 14)));
        $this->assertSame(64, DuplicateGuard::hammingDistance(str_repeat('0', 16), str_repeat('f', 16)));
    }

    public function test_malformed_hashes_throw(): void
    {
        foreach (['xyz', str_repeat('0', 15), str_repeat('g', 16), ''] as $bad) {
            try {
                DuplicateGuard::hammingDistance($bad, str_repeat('0', 16));
                $this->fail("Malformed hash {$bad} was accepted");
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    private function seedFeedback(Product $product, string $photoHash, string $dhash): SearchFeedback
    {
        return SearchFeedback::create([
            'user_id' => User::factory()->create()->id, 'confirmed_product_id' => $product->id,
            'predicted_sku' => $product->sku, 'confirmed_sku' => $product->sku,
            'disk' => 'local', 'query_image_path' => 'verified-search/seed.webp',
            'photo_hash' => $photoHash, 'dhash' => $dhash, 'training_status' => 'verified',
        ]);
    }

    public function test_identical_photo_hash_is_exact_regardless_of_sku_scope(): void
    {
        $product = Product::create(['sku' => 'SKU-A']);
        $seed = $this->seedFeedback($product, str_repeat('a', 64), str_repeat('0', 16));
        $result = DuplicateGuard::check(str_repeat('a', 64), str_repeat('f', 16), $product->id);
        $this->assertSame(DuplicateGuard::EXACT, $result['status']);
        $this->assertSame(0, $result['distance']);
        $this->assertSame($seed->id, $result['duplicate_of']);
    }

    public function test_near_duplicate_within_same_sku_reports_distance(): void
    {
        $product = Product::create(['sku' => 'SKU-A']);
        $seed = $this->seedFeedback($product, str_repeat('a', 64), str_repeat('0', 16));
        // Flip two bits: distance 2 <= default threshold 4.
        $result = DuplicateGuard::check(str_repeat('b', 64), '03'.str_repeat('0', 14), $product->id);
        $this->assertSame(DuplicateGuard::NEAR, $result['status']);
        $this->assertSame(2, $result['distance']);
        $this->assertSame($seed->id, $result['duplicate_of']);
    }

    public function test_different_sku_is_not_flagged_by_similarity(): void
    {
        $one = Product::create(['sku' => 'SKU-A']);
        $two = Product::create(['sku' => 'SKU-B']);
        $this->seedFeedback($one, str_repeat('a', 64), str_repeat('0', 16));
        $result = DuplicateGuard::check(str_repeat('b', 64), '03'.str_repeat('0', 14), $two->id);
        $this->assertSame(DuplicateGuard::UNIQUE, $result['status']);
        $this->assertNull($result['duplicate_of']);
    }

    public function test_genuinely_different_view_is_unique(): void
    {
        $product = Product::create(['sku' => 'SKU-A']);
        $this->seedFeedback($product, str_repeat('a', 64), str_repeat('0', 16));
        $result = DuplicateGuard::check(str_repeat('b', 64), str_repeat('f', 16), $product->id);
        $this->assertSame(DuplicateGuard::UNIQUE, $result['status']);
        $this->assertSame(64, $result['distance']);
    }

    public function test_threshold_is_configurable_not_universal(): void
    {
        $product = Product::create(['sku' => 'SKU-A']);
        $this->seedFeedback($product, str_repeat('a', 64), str_repeat('0', 16));
        // Distance 5: unique under default 4, near under 6.
        config(['retrieval.reference_max_dhash_distance' => 6]);
        $this->assertSame(DuplicateGuard::NEAR,
            DuplicateGuard::check(str_repeat('b', 64), '1f'.str_repeat('0', 14), $product->id)['status']);
        config(['retrieval.reference_max_dhash_distance' => 4]);
        $this->assertSame(DuplicateGuard::UNIQUE,
            DuplicateGuard::check(str_repeat('c', 64), '1f'.str_repeat('0', 14), $product->id)['status']);
    }
}
