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
        $defaults = [
            'pipeline' => config('visual_search.pipeline'),
            'threshold' => (float) config('visual_search.threshold'),
            'min_margin' => (float) config('visual_search.min_margin'),
        ];
        $path = config('visual_search.policy_path');
        if (! is_string($path) || ! is_file($path)) {
            return $defaults;
        }
        try {
            $policy = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $defaults;
        }

        if (! is_array($policy) || ($policy['pipeline'] ?? null) !== $defaults['pipeline']) {
            return $defaults;
        }

        return array_merge($defaults, [
            'threshold' => is_numeric($policy['threshold'] ?? null)
                ? (float) $policy['threshold'] : $defaults['threshold'],
            'min_margin' => is_numeric($policy['min_margin'] ?? null)
                ? (float) $policy['min_margin'] : $defaults['min_margin'],
        ]);
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
        $signals = [];

        foreach (['color', 'texture', 'pattern', 'shape'] as $name) {
            $a = $query[$name] ?? null;
            $b = $reference[$name] ?? null;

            if (! is_array($a) || ! is_array($b) || ! $a || count($a) !== count($b)) {
                continue;
            }

            if ($name === 'shape') {
                $signals[$name] = min($a[0], $b[0])
                    / max($a[0], $b[0], 0.000001);

                continue;
            }

            $sumA = array_sum($a);
            $sumB = array_sum($b);

            if ($sumA <= 0 || $sumB <= 0) {
                continue;
            }

            $intersection = 0.0;

            foreach ($a as $i => $value) {
                $intersection += min(
                    $value / $sumA,
                    $b[$i] / $sumB
                );
            }

            $signals[$name] = $intersection;
        }

        if (! $signals) {
            return $global;
        }

        // Ambil evidence visual terkuat.
        // Descriptor yang berubah karena viewpoint tidak langsung dianggap mismatch.
        rsort($signals, SORT_NUMERIC);

        $strongest = array_slice(
            $signals,
            0,
            min(2, count($signals))
        );

        $visualSupport = array_sum($strongest) / count($strongest);

        // CLIP tetap sinyal utama.
        // Descriptor hanya membantu verification.
        return (0.75 * $global) + (0.25 * $visualSupport);
    }

    public function search(array $query): Collection
    {
        $started = microtime(true);
        $policy = $this->policy();
        $candidates = $this->candidates($query);
        $ranked = $candidates->map(function ($row) use ($query) {
            $row->score = $this->score(
                (float) $row->similarity,
                $query['features'],
                json_decode($row->features, true, 32, JSON_THROW_ON_ERROR)
            );

            return $row;
        })->sortByDesc('score')->unique('product_id')->values();

        $best = $ranked->get(0);
        $second = $ranked->get(1);
        $margin = $best ? ($second ? $best->score - $second->score : 1.0) : null;

        if (! $best || $best->score < $policy['threshold'] || $margin < $policy['min_margin']) {
            $accepted = collect();
        } else {
            $accepted = $ranked->filter(fn ($row) => $row->score >= $policy['threshold'])->take(12)->values();
        }

        Log::info('visual_search_decision', [
            'best_product_id' => $best?->product_id,
            'best_score' => $best?->score,
            'second_score' => $second?->score,
            'margin' => $margin,
            'threshold' => $policy['threshold'],
            'min_margin' => $policy['min_margin'],
            'results' => $accepted->count(),
            'no_match' => $accepted->isEmpty(),
        ]);
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
