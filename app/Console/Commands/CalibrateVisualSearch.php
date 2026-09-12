<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\VisualRepresentation;
use App\Services\VisualSearch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CalibrateVisualSearch extends Command
{
    protected $signature = 'products:calibrate-visual {dataset : JSONL with path, sha256, capture_group, relevant_skus} {--output= : New policy file}';

    protected $description = 'Calibrate final relevance from labelled positive and negative query photos';

    public function handle(VisualRepresentation $client, VisualSearch $search): int
    {
        $output = $this->option('output') ?: config('visual_search.policy_path');
        if (is_file($output)) {
            $this->error('Policy already exists; choose a new --output file.');

            return self::FAILURE;
        }
        $handle = fopen($this->argument('dataset'), 'rb');
        $positives = $negatives = 0;
        $positiveScores = $wrongScores = $seen = [];
        try {
            while (($line = fgets($handle, 1024 * 1024)) !== false) {
                $row = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                $hash = hash_file('sha256', $row['path']);
                if ($hash !== $row['sha256'] || isset($seen[$hash]) || empty($row['capture_group']) || ! is_array($row['relevant_skus'])) {
                    throw new \RuntimeException('Invalid, duplicate or changed labelled query.');
                }
                if (DB::table('visual_references')->where('source_hash', $hash)->exists()) {
                    throw new \RuntimeException('Query duplicates a reference photo.');
                }
                $seen[$hash] = $row['capture_group'];
                $row['relevant_skus'] ? $positives++ : $negatives++;
                $query = $client->extract($row['path']);
                $candidates = $search->candidates($query);
                $skus = Product::whereIn('id', $candidates->pluck('product_id'))->pluck('sku', 'id');
                $best = null;
                foreach ($candidates as $candidate) {
                    $score = $search->score($candidate->similarity, $query['features'], json_decode($candidate->features, true, 32, JSON_THROW_ON_ERROR));
                    if (in_array((string) $skus[$candidate->product_id], $row['relevant_skus'], true)) {
                        $best = $best === null ? $score : max($best, $score);
                    } else {
                        $wrongScores[] = $score;
                    }
                }
                if ($row['relevant_skus']) {
                    if ($best === null) {
                        throw new \RuntimeException('A positive query has no relevant candidate; do not freeze this policy.');
                    }
                    $positiveScores[] = $best;
                }
            }
            if (! $positives || ! $negatives || ! $wrongScores) {
                throw new \RuntimeException('Both labelled positive and negative queries are required.');
            }
            // User priority: reject wrong SKUs while preserving known matches.
            $wrongMax = max($wrongScores);
            $positiveMin = min($positiveScores);
            if ($wrongMax >= $positiveMin) {
                throw new \RuntimeException("No separating threshold: wrong max={$wrongMax}, relevant min={$positiveMin}. Calibration not applied.");
            }
            $policy = ['pipeline' => config('visual_search.pipeline'), 'threshold' => ($wrongMax + $positiveMin) / 2,
                'positive_queries' => $positives, 'negative_queries' => $negatives,
                'dataset_sha256' => hash_file('sha256', $this->argument('dataset')),
                'capture_groups' => array_values(array_unique($seen)), 'query_hashes' => array_keys($seen),
                'held_out_validated' => false, 'objective' => 'zero observed FP and retain every positive query',
                'wrong_max' => $wrongMax, 'relevant_min' => $positiveMin];
            $file = fopen($output, 'x');
            fwrite($file, json_encode($policy, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            fclose($file);
            $this->info("Policy saved: {$output}. Tuning only; verify independent photos before production.");

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            fclose($handle);
        }
    }
}
