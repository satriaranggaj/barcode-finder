<?php

// Local-only TEMP tables shadow the application table names. All work is rolled back.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Services\ImageSearch;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$db = DB::connection();
if (! app()->environment('local') || $db->getDriverName() !== 'pgsql' || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('Local PostgreSQL only.');
}
$report = ['synthetic_vectors_only' => true, 'sku_accuracy' => null, 'dimensions' => 512, 'ef_search' => 100, 'runs' => []];
$db->disableQueryLog();
$db->beginTransaction();
try {
    $db->statement("SET LOCAL maintenance_work_mem = '64MB'");
    $db->statement("SET LOCAL statement_timeout = '15min'");
    $db->statement('CREATE TEMP TABLE products (id bigint PRIMARY KEY, sku text) ON COMMIT DROP');
    $db->statement('CREATE TEMP TABLE product_photos (id bigint PRIMARY KEY, product_id bigint, path text) ON COMMIT DROP');
    $db->statement('CREATE TEMP TABLE product_photo_features (id bigint PRIMARY KEY, product_photo_id bigint, source_path text, version text, embedding vector(512)) ON COMMIT DROP');
    $db->select('SELECT setseed(0.42)');
    foreach ([10000, 100000] as $n) {
        $db->statement('TRUNCATE pg_temp.product_photo_features, pg_temp.product_photos, pg_temp.products');
        $db->statement('DROP INDEX IF EXISTS pg_temp.bench_v2_hnsw');
        $db->statement("INSERT INTO pg_temp.products SELECT i, i::text FROM generate_series(1, {$n}) i");
        $db->statement("INSERT INTO pg_temp.product_photos SELECT i, i, 'bench/' || i FROM generate_series(1, {$n}) i");
        echo "Generating {$n} synthetic vectors...\n";
        // Correlated subquery: independently randomized vectors, never one repeated vector.
        $db->statement("INSERT INTO pg_temp.product_photo_features SELECT i, i, 'bench/' || i, 'object-v2',
            ARRAY(SELECT (random()*2-1+i*0)::real FROM generate_series(1,512))::vector
            FROM generate_series(1, {$n}) i");
        echo "Building {$n}-row HNSW with 64 MiB maintenance memory...\n";
        $start = microtime(true);
        $db->statement("CREATE INDEX bench_v2_hnsw ON pg_temp.product_photo_features USING hnsw (embedding vector_cosine_ops) WHERE version = 'object-v2'");
        $build = microtime(true) - $start;
        foreach (['products', 'product_photos', 'product_photo_features'] as $table) {
            $db->statement("ANALYZE pg_temp.{$table}");
        }
        $queries = $db->select('SELECT ARRAY(SELECT (random()*2-1+i*0)::real FROM generate_series(1,512))::vector::text AS vector FROM generate_series(1,5) i');
        foreach ([20, 30, 50] as $k) {
            config(['image_search.candidates' => $k, 'image_search.ef_search' => 100]);
            $recalls = $exactMs = $annMs = [];
            foreach ($queries as $q) {
                $vector = json_decode($q->vector, true, flags: JSON_THROW_ON_ERROR);
                $db->statement('SET LOCAL enable_indexscan = off');
                $db->statement('SET LOCAL enable_bitmapscan = off');
                $start = microtime(true);
                $exact = app(ImageSearch::class)->candidates($vector, true)->pluck('photo_id')->all();
                $exactMs[] = (microtime(true) - $start) * 1000;
                $db->statement('SET LOCAL enable_indexscan = on');
                $db->statement('SET LOCAL enable_bitmapscan = on');
                $start = microtime(true);
                $ann = app(ImageSearch::class)->candidates($vector, true)->pluck('photo_id')->all();
                $annMs[] = (microtime(true) - $start) * 1000;
                $recalls[] = count(array_intersect($exact, $ann)) / $k;
            }
            $plan = $db->select("EXPLAIN (FORMAT JSON) SELECT id FROM pg_temp.product_photo_features WHERE version = 'object-v2' ORDER BY embedding <=> CAST(? AS vector) LIMIT {$k}", [$queries[0]->vector]);
            $plan = json_decode($plan[0]->{'QUERY PLAN'}, true)[0]['Plan'];
            $summary = function (array $v): array {
                sort($v);

                return ['median' => $v[2], 'p95' => $v[3] + .8 * ($v[4] - $v[3])];
            };
            $run = ['rows' => $n, 'k' => $k, 'queries' => 5, 'build_seconds' => $build,
                'ann_recall_at_k' => array_sum($recalls) / count($recalls), 'exact_ms' => $summary($exactMs), 'ann_ms' => $summary($annMs),
                'plan_node' => $plan['Plans'][0]['Node Type'] ?? null, 'index' => $plan['Plans'][0]['Index Name'] ?? null];
            $report['runs'][] = $run;
            echo json_encode($run).PHP_EOL;
        }
    }
} finally {
    $db->rollBack();
}
file_put_contents(__DIR__.'/../ai-service/benchmark-output/pgvector-scale.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
