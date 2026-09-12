<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VisualSearch
{
    public function policy(): array
    {
        $path = config('visual_search.policy_path');
        if (! is_file($path)) {
            throw new \RuntimeException('Visual relevance policy has not been calibrated.');
        }
        $policy = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (($policy['pipeline'] ?? null) !== config('visual_search.pipeline')
            || ! is_numeric($policy['threshold'] ?? null) || ! is_finite((float) $policy['threshold'])
            || $policy['threshold'] < -1 || $policy['threshold'] > 1
            || ($policy['positive_queries'] ?? 0) < 1 || ($policy['negative_queries'] ?? 0) < 1) {
            throw new \RuntimeException('Invalid relevance policy.');
        }

        return $policy;
    }

    public function candidates(array $query): Collection
    {
        $k = max(1, min(100, (int) config('visual_search.candidates')));
        if (DB::getDriverName() !== 'pgsql') {
            throw new \RuntimeException('Visual search requires pgvector.');
        }
        $vector = '['.implode(',', $query['embedding']).']';

        return DB::transaction(function () use ($vector, $k): Collection {
            DB::select("SELECT set_config('hnsw.ef_search', ?, true)", [(string) max($k, min(1000, config('visual_search.ef_search')))]);

            // Limit vectors first, then load only the selected reference features.
            return collect(DB::select('SELECT nearest.photo_id, nearest.similarity, r.features, p.product_id, p.path
                FROM (SELECT photo_id, 1 - (embedding <=> CAST(? AS vector)) AS similarity
                      FROM visual_references WHERE pipeline = ?
                      ORDER BY embedding <=> CAST(? AS vector) LIMIT ?) nearest
                JOIN visual_references r ON r.photo_id = nearest.photo_id
                JOIN product_photos p ON p.id = nearest.photo_id AND p.path = r.source_path',
                [$vector, config('visual_search.pipeline'), $vector, $k]));
        });
    }

    public function score(float $global, array $query, array $reference): float
    {
        $compatibility = [];
        foreach (['color', 'texture', 'pattern', 'shape'] as $name) {
            $a = $query[$name] ?? null;
            $b = $reference[$name] ?? null;
            if (! is_array($a) || ! is_array($b) || ! $a || count($a) !== count($b)) {
                continue;
            }
            if ($name === 'shape') {
                $compatibility[] = min($a[0], $b[0]) / max($a[0], $b[0], .000001);
            } elseif (array_sum($a) > 0 && array_sum($b) > 0) {
                $intersection = 0;
                foreach ($a as $i => $value) {
                    $intersection += min($value / array_sum($a), $b[$i] / array_sum($b));
                }
                $compatibility[] = $intersection;
            }
        }

        // Conservative agreement gate: disagreement lowers global score. Missing
        // descriptors are not fabricated as mismatches. Threshold is calibrated
        // against THIS score, not the old raw cosine threshold.
        return $compatibility ? min($global, array_sum($compatibility) / count($compatibility)) : -1;
    }

    public function search(array $query): Collection
    {
        $started = microtime(true);
        $policy = $this->policy();
        $candidates = $this->candidates($query);
        $accepted = $candidates->map(function ($row) use ($query) {
            $row->score = $this->score((float) $row->similarity, $query['features'], json_decode($row->features, true, 32, JSON_THROW_ON_ERROR));

            return $row;
        })->filter(fn ($row) => $row->score >= $policy['threshold'])
            ->sortByDesc('score')->unique('product_id')->take(12)->values();
        $products = Product::whereIn('id', $accepted->pluck('product_id'))->get()->keyBy('id');
        $results = $accepted->map(function ($row) use ($products) {
            $product = $products->get($row->product_id);
            if ($product) {
                $product->photo = $row->path;
                $product->similarity = $row->score;
            }

            return $product;
        })->filter()->values();
        Log::info('visual_search', ['pipeline' => $query['pipeline'], 'candidates' => $candidates->count(),
            'results' => $results->count(), 'no_match' => $results->isEmpty(), 'search_ms' => round((microtime(true) - $started) * 1000)]);

        return $results;
    }
}
