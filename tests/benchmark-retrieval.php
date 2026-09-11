<?php

// Offline real-SKU ablations, using the application scorer. No database writes.
// Fixed hypotheses are NOT tuned on held-out results or activated in config.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Services\ImageSearch;
use App\Services\VisualReranker;
use Illuminate\Contracts\Console\Kernel;

function percentile(array $values, float $q): float
{
    sort($values);
    $index = (count($values) - 1) * $q;

    return $values[(int) floor($index)] + ($values[(int) ceil($index)] - $values[(int) floor($index)]) * ($index - floor($index));
}

$base = rtrim($argv[1] ?? __DIR__.'/../ai-service/benchmark-output/', '/\\').DIRECTORY_SEPARATOR;
$lazy = json_decode(file_get_contents($base.'real-lazy.json'), true, flags: JSON_THROW_ON_ERROR);
$report = ['acceptance_ready' => false, 'threshold' => null,
    'reason' => 'Exploratory ablations only; no calibrated policy or verified acceptance dataset.',
    'latency_scope' => 'Composed measured CPU AI + offline exact scoring + stateless descriptor pass if needed; excludes HTTP, queue, pgvector and page rendering.',
    'policy' => 'Predeclared ablations: global=1, tested descriptor=0.25. No weights or threshold selected from evaluation.', 'runs' => []];
$ranker = new VisualReranker;
config(['image_search.adaptive_reranking' => true, 'image_search.confidence_similarity' => .90, 'image_search.confidence_margin' => .08]);
$manifestHash = null;
foreach (['clip', 'dinov2'] as $model) {
    $data = json_decode(file_get_contents($base.'real-'.$model.'.json'), true, flags: JSON_THROW_ON_ERROR);
    $manifestHash ??= $data['manifest_sha256'];
    if ($manifestHash !== $data['manifest_sha256']) {
        throw new RuntimeException('Models must use the same manifest.');
    }
    foreach (['tuning', 'evaluation'] as $split) {
        foreach ([20, 30, 50] as $k) {
            foreach (['original', 'v2', 'shape', 'texture', 'local', 'color', 'proportion', 'adaptive'] as $variant) {
                $mode = $variant === 'original' ? 'original' : 'v2';
                $signal = in_array($variant, ['original', 'v2']) ? null : ($variant === 'adaptive' ? 'local' : $variant);
                $hits1 = $hits5 = $mrr = $recall = $reranked = 0;
                $latencies = $ranks = [];
                foreach ($data[$split] as $index => $query) {
                    $start = microtime(true);
                    $rows = [];
                    foreach ($data['references'] as $ref) {
                        $rows[] = ['id' => $ref['sku'], 'similarity' => ImageSearch::cosine($query[$mode]['embedding'], $ref[$mode]['embedding']), 'ref' => $ref];
                    }
                    usort($rows, fn ($a, $b) => $b['similarity'] <=> $a['similarity']);
                    $rows = array_slice($rows, 0, $k);
                    $recall += $query['sku'] !== null && in_array($query['sku'], array_column($rows, 'id')) ? 1 : 0;
                    $rerank = $signal !== null && ($variant !== 'adaptive' || (new ImageSearch)->isAmbiguous(collect($rows)->map(fn ($r) => (object) $r)));
                    $reranked += (int) $rerank;
                    foreach ($rows as &$row) {
                        $row['score'] = $rerank ? $ranker->score($query[$mode]['descriptors'], $row['ref'][$mode]['descriptors'], $row['similarity'], ['global' => 1, $signal => .25]) : $row['similarity'];
                    }
                    unset($row);
                    usort($rows, fn ($a, $b) => $b['score'] <=> $a['score']);
                    $skus = array_values(array_unique(array_column($rows, 'id')));
                    $rank = $query['sku'] === null ? false : array_search($query['sku'], $skus);
                    $hits1 += $rank === 0 ? 1 : 0;
                    $hits5 += $rank !== false && $rank < 5 ? 1 : 0;
                    $mrr += $rank !== false ? 1 / ($rank + 1) : 0;
                    $ranks[] = ['photo_id' => $query['photo_id'], 'case' => $query['case'], 'rank' => $rank === false ? null : $rank + 1];
                    $scoring = (microtime(true) - $start) * 1000;
                    foreach ($query[$mode]['samples'] as $i => $sample) {
                        if ($lazy[$split][$index]['photo_id'] !== $query['photo_id']) {
                            throw new RuntimeException('Descriptor timings must use the same query order.');
                        }
                        $latencies[] = $sample['fast_total_ms'] + $scoring + ($rerank ? $lazy[$split][$index]['samples_ms'][$signal][$i] : 0);
                    }
                }
                $n = count($data[$split]);
                $positives = count(array_filter($data[$split], fn ($q) => $q['sku'] !== null));
                $report['runs'][] = ['model' => $model, 'variant' => $variant, 'split' => $split, 'k' => $k, 'queries' => $n,
                    'positive_queries' => $positives, 'negative_queries' => $n - $positives,
                    'top1' => $positives ? $hits1 / $positives : null, 'top5' => $positives ? $hits5 / $positives : null,
                    'mrr' => $positives ? $mrr / $positives : null, 'recall_at_k' => $positives ? $recall / $positives : null,
                    'reranked' => $reranked, 'median_ms' => percentile($latencies, .5), 'p95_ms' => percentile($latencies, .95),
                    'rss_mib' => $data['environment']['rss_mib'], 'ranks' => $ranks];
            }
        }
    }
}
file_put_contents($base.'real-ablation.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
foreach ($report['runs'] as $run) {
    if ($run['split'] === 'evaluation' && $run['k'] === 30) {
        echo json_encode($run).PHP_EOL;
    }
}
