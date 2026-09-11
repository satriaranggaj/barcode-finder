<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ImageSearch
{
    public function search(array $embedding, ?array $features = null, ?\Closure $loadDescriptors = null): Collection
    {
        $started = microtime(true);
        $candidates = $this->candidates($embedding, $features !== null);
        $retrievalMs = (microtime(true) - $started) * 1000;
        $rerankStarted = microtime(true);
        $weights = config('image_search.weights');
        $signals = collect($weights)->except('global')->filter(fn ($weight) => $weight > 0)->keys()->all();
        $rerank = $features !== null && $signals && $this->isAmbiguous($candidates);
        $queryDescriptors = $features['descriptors'] ?? [];
        if ($rerank && $loadDescriptors !== null) {
            try {
                $queryDescriptors = $loadDescriptors($signals);
            } catch (\Throwable $error) {
                $rerank = false;
                Log::warning('image_search_descriptor_fallback', ['exception' => $error::class]);
            }
        }
        $metadata = $rerank ? DB::table('product_photo_features')->whereIn('id', $candidates->pluck('feature_id'))
            ->pluck('metadata', 'id') : collect();
        foreach ($candidates as $row) {
            $row->raw_cosine = (float) $row->similarity;
            $row->score = $row->similarity;
            if ($rerank) {
                $reference = json_decode($metadata[$row->feature_id] ?? '{}', true);
                $row->score = app(VisualReranker::class)->score($queryDescriptors, $reference['descriptors'] ?? [], $row->similarity, $weights);
            }
            $row->final_score = $row->score;
        }
        $minimum = (float) config('services.ai.search_min_similarity', 0.72);
        $finalMinimum = $features !== null ? config('image_search.final_min_score') : null;
        if ($finalMinimum !== null && (! is_numeric($finalMinimum) || ! is_finite((float) $finalMinimum) || $finalMinimum < -1 || $finalMinimum > 1)) {
            throw new RuntimeException('Invalid calibrated final relevance threshold.');
        }
        $results = $candidates->filter(fn ($row) => $finalMinimum !== null
            ? $row->final_score >= $finalMinimum : $row->similarity >= $minimum)
            ->sortByDesc('score')->unique('id')->take(12)->values();
        Log::info('image_search', ['version' => $features ? ImageFeatureClient::VERSION : 'legacy',
            'candidate_count' => $candidates->count(), 'result_count' => $results->count(),
            'reranked' => (bool) $rerank,
            'retrieval_ms' => round($retrievalMs, 2), 'reranking_ms' => round((microtime(true) - $rerankStarted) * 1000, 2),
            'total_ms' => round((microtime(true) - $started) * 1000, 2)]);

        return $results->map(fn ($row) => (new Product)->newFromBuilder((array) $row));
    }

    public function candidates(array $embedding, bool $object): Collection
    {
        $limit = max(1, min(100, (int) config('image_search.candidates', 50)));
        if (DB::connection()->getDriverName() === 'pgsql') {
            return DB::transaction(function () use ($embedding, $object, $limit): Collection {
                $ef = max($limit, min(1000, (int) config('image_search.ef_search', 100)));
                DB::statement("SET LOCAL hnsw.ef_search = {$ef}");
                $vector = '['.implode(',', $embedding).']';
                if ($object) {
                    // Limit nearest photos BEFORE joining catalog rows or reading descriptors.
                    return collect(DB::select("SELECT p.*, ph.path AS photo, nearest.id AS feature_id,
                        ph.id AS photo_id, 1 - nearest.distance AS similarity
                        FROM (SELECT id, product_photo_id, source_path, embedding <=> CAST(? AS vector) AS distance
                              FROM product_photo_features WHERE version = 'object-v2'
                              ORDER BY embedding <=> CAST(? AS vector) LIMIT {$limit}) AS nearest
                        JOIN product_photos ph ON ph.id = nearest.product_photo_id AND ph.path = nearest.source_path
                        JOIN products p ON p.id = ph.product_id
                        ORDER BY nearest.distance", [$vector, $vector]));
                }

                return collect(DB::select("SELECT p.*, ph.path AS photo, ph.id AS photo_id, 1 - nearest.distance AS similarity
                    FROM (SELECT id, embedding <=> CAST(? AS vector) AS distance FROM product_photos
                          WHERE embedding IS NOT NULL ORDER BY embedding <=> CAST(? AS vector) LIMIT {$limit}) AS nearest
                    JOIN product_photos ph ON ph.id = nearest.id JOIN products p ON p.id = ph.product_id
                    ORDER BY nearest.distance", [$vector, $vector]));
            });
        }
        if (! app()->environment('testing')) {
            throw new RuntimeException('Scalable image retrieval requires PostgreSQL/pgvector.');
        }
        // SQLite is exclusively the deterministic test adapter, never production retrieval.
        $query = DB::table('product_photos as ph')->join('products as p', 'p.id', '=', 'ph.product_id');
        if ($object) {
            $query->join('product_photo_features as f', 'f.product_photo_id', '=', 'ph.id')
                ->where('f.version', ImageFeatureClient::VERSION)->whereColumn('f.source_path', 'ph.path')
                ->selectRaw('p.*, ph.path AS photo, ph.id AS photo_id, f.id AS feature_id, f.embedding AS vector');
        } else {
            $query->whereNotNull('ph.embedding')->selectRaw('p.*, ph.path AS photo, ph.id AS photo_id, ph.embedding AS vector');
        }

        return $query->get()->map(function ($row) use ($embedding) {
            $values = array_map('floatval', explode(',', trim($row->vector, '[]')));
            $row->similarity = self::cosine($embedding, $values);
            unset($row->vector);

            return $row;
        })->sortByDesc('similarity')->take($limit)->values();
    }

    public static function cosine(array $left, array $right): float
    {
        if (count($left) !== count($right)) {
            return 0.0;
        }
        $dot = $a = $b = 0.0;
        foreach ($left as $i => $value) {
            $dot += $value * $right[$i];
            $a += $value * $value;
            $b += $right[$i] * $right[$i];
        }

        return $a && $b ? $dot / sqrt($a * $b) : 0.0;
    }

    public function isAmbiguous(Collection $candidates): bool
    {
        // Compare distinct SKUs, not two photographs of the same SKU.
        $best = $candidates->sortByDesc('similarity')->unique('id')->take(2)->values();
        if ($best->count() < 2) {
            return false; // Reranking cannot change which SKU wins.
        }
        if (! config('image_search.adaptive_reranking', true)) {
            return true;
        }

        return $best[0]->similarity < (float) config('image_search.confidence_similarity', .90)
            || $best[0]->similarity - $best[1]->similarity < (float) config('image_search.confidence_margin', .08);
    }
}
