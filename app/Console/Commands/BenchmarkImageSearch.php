<?php

namespace App\Console\Commands;

use App\Services\ImageSearch;
use App\Services\VisualReranker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('images:benchmark {features : JSON from ai-service/benchmark.py} {--output=} {--weights= : Experimental weights as JSON}')]
#[Description('Offline accuracy benchmark; never writes application data')]
class BenchmarkImageSearch extends Command
{
    public function handle(VisualReranker $ranker): int
    {
        $path = $this->argument('features');
        if (! is_file($path) || filesize($path) > 64 * 1024 * 1024) {
            $this->error('Feature file missing or above 64 MiB.');

            return self::FAILURE;
        }
        try {
            $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $weights = $this->option('weights') ? json_decode($this->option('weights'), true, 32, JSON_THROW_ON_ERROR) : config('image_search.weights');
            if (count($data['references']) > 1000 || count($data['queries']) > 1000 || ! $data['queries'] || ! $data['references']) {
                throw new \RuntimeException('Use 1..1000 references and queries for offline accuracy tests.');
            }
            $report = ['dataset_note' => $data['dataset_note'], 'environment' => $data['environment'],
                'latency_ms' => $data['latency_ms'], 'weights' => $weights,
                'retrieval' => 'Offline exact cosine, NOT pgvector. Database latency/ANN recall needs PostgreSQL validation.', 'runs' => []];
            foreach (['old', 'new'] as $mode) {
                foreach ([30, 50, 75, 100] as $k) {
                    $hits = [1 => 0, 5 => 0, 10 => 0];
                    $recall = $mrr = $retrievalMs = $rerankMs = 0;
                    $cases = [];
                    foreach ($data['queries'] as $query) {
                        $start = microtime(true);
                        $rows = [];
                        foreach ($data['references'] as $ref) {
                            $global = ImageSearch::cosine($query[$mode]['embedding'], $ref[$mode]['embedding']);
                            $rows[] = ['sku' => (string) $ref['sku'], 'global' => $global, 'features' => $ref[$mode]];
                        }
                        usort($rows, fn ($a, $b) => $b['global'] <=> $a['global']);
                        $rows = array_slice($rows, 0, $k);
                        $retrievalMs += (microtime(true) - $start) * 1000;
                        $start = microtime(true);
                        $recall += in_array((string) $query['sku'], array_column($rows, 'sku'), true) ? 1 : 0;
                        foreach ($rows as &$row) {
                            $row['score'] = $mode === 'old' ? $row['global'] : $ranker->score($query[$mode]['descriptors'] ?? [], $row['features']['descriptors'] ?? [], $row['global'], $weights);
                        }
                        unset($row);
                        usort($rows, fn ($a, $b) => $b['score'] <=> $a['score']);
                        $skus = array_values(array_unique(array_column(array_filter($rows, fn ($row) => $row['global'] >= config('services.ai.search_min_similarity', .72)), 'sku')));
                        $rank = array_search((string) $query['sku'], $skus, true);
                        foreach ($hits as $cutoff => $_) {
                            $hits[$cutoff] += $rank !== false && $rank < $cutoff ? 1 : 0;
                        }
                        $mrr += $rank !== false ? 1 / ($rank + 1) : 0;
                        $case = $query['case'] ?? 'unspecified';
                        $cases[$case]['queries'] = ($cases[$case]['queries'] ?? 0) + 1;
                        $cases[$case]['top1_hits'] = ($cases[$case]['top1_hits'] ?? 0) + ($rank === 0 ? 1 : 0);
                        $rerankMs += (microtime(true) - $start) * 1000;
                    }
                    $n = count($data['queries']);
                    $report['runs'][] = ['mode' => $mode, 'k' => $k, 'queries' => $n, 'top1' => $hits[1] / $n, 'top5' => $hits[5] / $n,
                        'top10' => $hits[10] / $n, 'recall_at_k' => $recall / $n, 'mrr_at_k' => $mrr / $n,
                        'offline_exact_mean_ms' => $retrievalMs / $n, 'reranking_mean_ms' => $rerankMs / $n, 'cases' => $cases];
                }
            }
            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            if ($this->option('output')) {
                file_put_contents($this->option('output'), $json);
            }
            $this->line($json);

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error('Benchmark failed: '.$error->getMessage());

            return self::FAILURE;
        }
    }
}
