<?php

namespace Tests\Unit;

use App\Services\FeedbackPayload;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FeedbackPayloadTest extends TestCase
{
    private function pending(array $overrides = []): array
    {
        return array_merge([
            'results' => [
                ['rank' => 1, 'sku' => 'TOP-1', 'image_id' => 'TOP-1/front.webp', 'product_id' => 7,
                    'score' => 0.91, 'visual_score' => 0.9, 'siglip_score' => 0.88, 'dino_score' => 0.92,
                    'local_score' => 0.5, 'text_score' => null, 'ocr_score' => null, 'matched_images' => 2],
                ['rank' => 2, 'sku' => 'TOP-2', 'score' => 0.42],
            ],
            'confidence' => 'high',
            'score_gap' => 0.49,
            'selection_source' => 'manual',
        ], $overrides);
    }

    public function test_valid_payload_is_normalized(): void
    {
        $payload = FeedbackPayload::verifiedAttributes($this->pending());
        $this->assertSame('high', $payload['confidence']);
        $this->assertEqualsWithDelta(0.49, $payload['score_gap'], 0.0001);
        $this->assertSame('manual', $payload['selection_source']);
        $this->assertCount(2, $payload['candidates']);
        $first = $payload['candidates'][0];
        $this->assertSame(1, $first['rank']);
        $this->assertSame('TOP-1', $first['sku']);
        $this->assertSame('TOP-1/front.webp', $first['image_id']);
        $this->assertSame(7, $first['product_id']);
        $this->assertEqualsWithDelta(0.91, $first['score'], 0.0001);
        $this->assertNull($first['text_score']);
        $this->assertSame(2, $first['matched_images']);
        $this->assertSame(0.42, $payload['candidates'][1]['score']);
    }

    public function test_missing_optional_keys_default_to_null(): void
    {
        $payload = FeedbackPayload::verifiedAttributes(['results' => [['sku' => 'A', 'score' => 0.5]]]);
        $this->assertNull($payload['confidence']);
        $this->assertNull($payload['score_gap']);
        $this->assertNull($payload['selection_source']);
        $this->assertSame(1, $payload['candidates'][0]['rank']);
        $this->assertNull($payload['candidates'][0]['image_id']);
    }

    public function test_unknown_keys_are_dropped_not_stored(): void
    {
        $payload = FeedbackPayload::verifiedAttributes(['results' => [
            ['sku' => 'A', 'score' => 0.5, 'future_debug_flag' => true],
        ]]);
        $this->assertArrayNotHasKey('future_debug_flag', $payload['candidates'][0]);
    }

    public function test_embedding_keys_are_rejected_fail_closed(): void
    {
        foreach (['embedding', 'embeddings', 'query_vector', 'imageVectors'] as $key) {
            try {
                FeedbackPayload::verifiedAttributes(['results' => [['sku' => 'A', 'score' => 0.5, $key => [0.1]]]]);
                $this->fail("Embedding key {$key} was accepted");
            } catch (ValidationException $error) {
                $this->assertNotEmpty($error->errors());
            }
        }
    }

    public function test_invalid_scores_ranks_skus_and_confidence_rejected(): void
    {
        $cases = [
            ['results' => [['sku' => 'A', 'score' => INF]]],
            ['results' => [['sku' => 'A', 'score' => 2]]],
            ['results' => [['sku' => 'A', 'score' => 0.5, 'dino_score' => NAN]]],
            ['results' => [['sku' => '', 'score' => 0.5]]],
            ['results' => [['sku' => str_repeat('X', 256), 'score' => 0.5]]],
            ['results' => [['sku' => 'A', 'score' => 0.5, 'rank' => 0]]],
            ['results' => 'not-an-array'],
            ['results' => [['sku' => 'A', 'score' => 0.5]], 'confidence' => 'certain'],
            ['results' => [['sku' => 'A', 'score' => 0.5]], 'score_gap' => 'huge'],
            ['results' => [['sku' => 'A', 'score' => 0.5]], 'selection_source' => 'magic'],
        ];
        foreach ($cases as $i => $pending) {
            try {
                FeedbackPayload::verifiedAttributes($pending);
                $this->fail("Invalid payload #{$i} was accepted");
            } catch (ValidationException $error) {
                $this->assertNotEmpty($error->errors());
            }
        }
    }

    public function test_candidate_count_is_bounded(): void
    {
        $results = [];
        for ($i = 0; $i <= FeedbackPayload::MAX_CANDIDATES; $i++) {
            $results[] = ['sku' => "SKU-{$i}", 'score' => 0.1];
        }
        $this->expectException(ValidationException::class);
        FeedbackPayload::verifiedAttributes(['results' => $results]);
    }

    public function test_sanitized_evidence_keeps_capture_group_and_drops_bytes(): void
    {
        $snapshot = FeedbackPayload::sanitizedEvidence([
            'capture_group' => 'session-abc',
            'evidence' => ['photo_hash' => str_repeat('a', 64), 'dhash' => str_repeat('0', 16),
                'blur' => 100, 'min_side' => 300, 'crop_pixels' => 90000, 'master' => 'BYTES'],
            'query' => ['reason' => 'manual_selection', 'models' => ['siglip'],
                'evaluation_signature' => ['huge' => true]],
            'results' => [['sku' => 'A', 'score' => 0.5]],
            'user_id' => 1, 'disk' => 'local', 'path' => 'search-pending/x.webp',
        ]);
        $this->assertSame('session-abc', $snapshot['capture_group']);
        $this->assertSame('manual_selection', $snapshot['query']['reason']);
        $this->assertArrayNotHasKey('master', $snapshot['quality']);
        $this->assertArrayNotHasKey('evaluation_signature', $snapshot['query']);
        $this->assertArrayNotHasKey('results', $snapshot);
        $this->assertArrayNotHasKey('user_id', $snapshot);
        $this->assertLessThanOrEqual(FeedbackPayload::MAX_JSON_BYTES, strlen(json_encode($snapshot)));
    }
}
