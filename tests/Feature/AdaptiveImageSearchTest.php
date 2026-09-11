<?php

namespace Tests\Feature;

use App\Services\ImageSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class AdaptiveImageSearchTest extends TestCase
{
    use RefreshDatabase;

    private function searcher(array $rows): ImageSearch
    {
        return new class($rows) extends ImageSearch
        {
            public function __construct(private array $rows) {}

            public function candidates(array $embedding, bool $object): Collection
            {
                return collect($this->rows)->map(fn ($row) => (object) $row);
            }
        };
    }

    public function test_confident_distinct_skus_skip_descriptors_and_metadata(): void
    {
        config(['image_search.weights' => ['global' => 1, 'local' => .2]]);
        $search = $this->searcher([
            ['id' => 1, 'similarity' => .99], ['id' => 1, 'similarity' => .98], ['id' => 2, 'similarity' => .80],
        ]);
        DB::enableQueryLog();
        $results = $search->search([1], [], function () {
            $this->fail('Descriptors must remain lazy.');
        });
        $this->assertCount(2, $results);
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_ambiguous_query_requests_only_enabled_signals_and_failure_preserves_global(): void
    {
        config(['image_search.weights' => ['global' => 1, 'local' => .2, 'texture' => 0, 'color' => 0]]);
        $search = $this->searcher([['id' => 1, 'similarity' => .85], ['id' => 2, 'similarity' => .84]]);
        $called = false;
        $results = $search->search([1], [], function ($signals) use (&$called) {
            $called = true;
            $this->assertSame(['local'], $signals);
            throw new \RuntimeException('Descriptor service unavailable');
        });
        $this->assertTrue($called);
        $this->assertSame(.85, $results[0]->final_score);
        $this->assertSame(.85, $results[0]->raw_cosine);
    }

    public function test_final_threshold_can_return_one_or_zero_without_affecting_legacy(): void
    {
        config(['image_search.weights' => ['global' => 1], 'image_search.final_min_score' => .86]);
        $search = $this->searcher([['id' => 1, 'similarity' => .9], ['id' => 2, 'similarity' => .8]]);
        $this->assertCount(1, $search->search([1], []));
        config(['image_search.final_min_score' => .95]);
        $results = $search->search([1], []);
        $this->assertCount(0, $results);
        $this->assertCount(2, $search->search([1]));
        $html = view('products.search', ['results' => $results, 'error' => null, 'errors' => new ViewErrorBag])->render();
        $this->assertStringContainsString('Tidak ditemukan barang yang cukup mirip', $html);
        $this->assertStringNotContainsString('% match', $html);
    }

    public function test_filter_uses_final_reranked_score_instead_of_raw_cosine(): void
    {
        config(['image_search.weights' => ['global' => 1, 'color' => 1],
            'image_search.adaptive_reranking' => false, 'image_search.final_min_score' => .7]);
        DB::table('products')->insert(['id' => 1, 'sku' => 'A']);
        DB::table('product_photos')->insert(['id' => 1, 'product_id' => 1, 'path' => 'a.jpg']);
        DB::table('product_photo_features')->insert(['id' => 1, 'product_photo_id' => 1,
            'version' => 'object-v2', 'source_path' => 'a.jpg', 'source_sha256' => str_repeat('a', 64),
            'embedding' => '[]', 'metadata' => json_encode(['descriptors' => ['version' => 'visual-v1', 'color' => [1, 0]]]), 'processed_at' => now()]);
        $search = $this->searcher([['id' => 1, 'feature_id' => 1, 'similarity' => .6],
            ['id' => 2, 'feature_id' => 1, 'similarity' => .5]]);
        $results = $search->search([1], [], fn () => ['version' => 'visual-v1', 'color' => [1, 0]]);
        $this->assertCount(2, $results);
        $this->assertSame(.6, $results[0]->raw_cosine);
        $this->assertSame(.8, $results[0]->final_score);
    }

    public function test_invalid_threshold_is_not_silently_converted_to_zero(): void
    {
        config(['image_search.final_min_score' => 'not-calibrated']);
        $this->expectException(\RuntimeException::class);
        $this->searcher([['id' => 1, 'similarity' => .9]])->search([1], []);
    }
}
