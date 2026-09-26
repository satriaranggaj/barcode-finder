<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Laravel ↔ FastAPI /search contract hardening for variant-cover evidence.
 *
 * Absent variant keys stay backward compatible; present-but-malformed values
 * (wrong types, unbounded lists, count mismatch) must fail controlled —
 * error view + empty results — never silently render a wrong fallback cover.
 */
class RetrievalContractTest extends TestCase
{
    use RefreshDatabase;

    private function searchWithResults(array $results): \Illuminate\Testing\TestResponse
    {
        config(['retrieval.driver' => 'faiss']);
        Storage::fake('public');
        Http::preventStrayRequests();
        Http::fake([rtrim(config('services.ai.url'), '/').'/search' => Http::response([
            'success' => true, 'confidence' => 'high', 'relevance_calibrated' => false,
            'results' => $results,
        ])]);

        return $this->post(route('products.search'), ['image' => UploadedFile::fake()->image('query.jpg')]);
    }

    private function validRow(string $sku, int|string $photoId): array
    {
        return ['rank' => 1, 'sku' => $sku, 'image_id' => $photoId.'.webp', 'score' => 0.91,
            'matched_images' => 2, 'matched_image_ids' => [$photoId.'.webp', 'verified-7.webp']];
    }

    public function test_valid_variant_evidence_renders_cover(): void
    {
        $product = Product::create(['sku' => 'CONTRACT-1']);
        $photo = $product->photos()->create(['path' => 'products/c.jpg', 'thumbnail_path' => 'thumbs/c.jpg']);

        $this->searchWithResults([$this->validRow('CONTRACT-1', (string) $photo->id)])
            ->assertOk()->assertViewHas('error', null)
            ->assertViewHas('results', fn ($rows) => $rows->count() === 1 && $rows->first()->photo === $photo->path);
    }

    #[DataProvider('malformedProvider')]
    public function test_malformed_variant_evidence_fails_controlled(callable $mutate): void
    {
        $product = Product::create(['sku' => 'CONTRACT-X']);
        $photo = $product->photos()->create(['path' => 'products/x.jpg', 'thumbnail_path' => 'thumbs/x.jpg']);
        $row = $this->validRow('CONTRACT-X', (string) $photo->id);
        $mutate($row);

        $this->searchWithResults([$row])
            ->assertOk()
            ->assertViewHas('error', fn ($error) => is_string($error) && $error !== '')
            ->assertViewHas('results', fn ($rows) => $rows->isEmpty());
    }

    public static function malformedProvider(): array
    {
        return [
            'matched_image_ids as string' => [function (&$row) { $row['matched_image_ids'] = '103.webp'; }],
            'matched_image_ids as object' => [function (&$row) { $row['matched_image_ids'] = ['a' => '103.webp']; }],
            'matched_image_ids with int item' => [function (&$row) { $row['matched_image_ids'] = [103]; }],
            'matched_image_ids with null item' => [function (&$row) { $row['matched_image_ids'] = [null]; }],
            'matched_image_ids with empty item' => [function (&$row) { $row['matched_image_ids'] = ['']; }],
            'matched_image_ids over bound' => [function (&$row) {
                $row['matched_images'] = 51;
                $row['matched_image_ids'] = array_map(fn ($i) => "ghost-{$i}.webp", range(1, 51));
            }],
            'matched_images negative' => [function (&$row) { $row['matched_images'] = -1; }],
            'matched_images as string' => [function (&$row) { $row['matched_images'] = '2'; }],
            'matched_images count mismatch' => [function (&$row) { $row['matched_images'] = 5; }],
            'image_id as array' => [function (&$row) { $row['image_id'] = ['103.webp']; }],
            'image_id empty string' => [function (&$row) { $row['image_id'] = ''; }],
            'image_id as object' => [function (&$row) { $row['image_id'] = ['name' => '103.webp']; }],
        ];
    }

    public function test_absent_variant_keys_stay_backward_compatible(): void
    {
        $product = Product::create(['sku' => 'CONTRACT-LEGACY']);
        $photo = $product->photos()->create(['path' => 'products/l.jpg', 'thumbnail_path' => 'thumbs/l.jpg']);

        $this->searchWithResults([['rank' => 1, 'sku' => 'CONTRACT-LEGACY',
                'image_id' => $photo->id.'.webp', 'score' => 0.9]])
            ->assertOk()->assertViewHas('error', null)
            ->assertViewHas('results', fn ($rows) => $rows->count() === 1 && $rows->first()->photo === $photo->path);
    }
}
