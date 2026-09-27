<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\VisualIndexBuilder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PostgreSQL integration proof for atomic JSON CAS.
 *
 * Production incident: `reconcileFeedback()` threw
 * `operator does not exist: json = unknown` because both `crop` columns are
 * PostgreSQL `json` (no `=` operator). This class runs the REAL
 * appendPending() flow (only the expensive Python boundary is stubbed)
 * against a dedicated throwaway PostgreSQL database and is SKIPPED when no
 * PostgreSQL is reachable — SQLite behavior is covered by JsonStateGuardTest
 * and never presented as proof of PostgreSQL compatibility.
 *
 * Safety: migrates ONLY a database whose name ends in `_cas_test`, created
 * separately. Never touches application or production databases.
 */
class PostgresJsonCasTest extends TestCase
{

    private const TEST_DB = 'barcodeidentify_cas_test';

    private bool $usingPgsql = false;

    protected function setUp(): void
    {
        parent::setUp();
        $config = $this->pgsqlConfig();
        if ($config === null || ! $this->ensureTestDatabase($config)) {
            $this->markTestSkipped('PostgreSQL not available for JSON CAS integration test.');
        }
        if (! str_ends_with((string) $config['database'], '_cas_test')) {
            $this->markTestSkipped('Refusing to migrate a non-test database.');
        }
        config(['database.connections.pgsql_cas' => $config]);
        config(['database.default' => 'pgsql_cas']);
        // Fresh schema per test; includes the real `json` crop columns.
        Artisan::call('migrate:fresh', ['--database' => 'pgsql_cas', '--force' => true]);
        $this->usingPgsql = true;
        $this->assertSame('pgsql', DB::connection()->getDriverName());
    }

    protected function tearDown(): void
    {
        if ($this->usingPgsql) {
            Artisan::call('migrate:fresh', ['--database' => 'pgsql_cas', '--force' => true]);
        }
        parent::tearDown();
    }

    /**
     * Dev-only connection info (same credentials as the committed
     * docker-compose dev service). Override via PG_CAS_* env vars.
     *
     * @return array<string, mixed>|null
     */
    private function pgsqlConfig(): ?array
    {
        return [
            'driver' => 'pgsql',
            'host' => env('PG_CAS_HOST', '127.0.0.1'),
            'port' => env('PG_CAS_PORT', '5432'),
            'database' => env('PG_CAS_DATABASE', self::TEST_DB),
            'username' => env('PG_CAS_USERNAME', 'barcodeidentify'),
            'password' => env('PG_CAS_PASSWORD', 'password'),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ];
    }

    private function ensureTestDatabase(array $config): bool
    {
        try {
            $pdo = new \PDO(
                "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']};connect_timeout=2",
                $config['username'], $config['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        } catch (\Throwable) {
            // Database may simply not exist yet: try creating it via the
            // maintenance database, else the suite skips honestly.
            try {
                $admin = new \PDO(
                    "pgsql:host={$config['host']};port={$config['port']};dbname=postgres;connect_timeout=2",
                    $config['username'], $config['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
                );
                $admin->exec('CREATE DATABASE "'.str_replace('"', '""', $config['database']).'"');
                $admin->exec('CREATE EXTENSION IF NOT EXISTS vector');
                $pdo = new \PDO(
                    "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']};connect_timeout=2",
                    $config['username'], $config['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
                );
            } catch (\Throwable) {
                return false;
            }
        }
        try {
            $pdo->exec('CREATE EXTENSION IF NOT EXISTS vector');
        } catch (\Throwable) {
        }

        return true;
    }

    private function cropType(string $table): ?string
    {
        return DB::selectOne(
            "SELECT format_type(a.atttypid, a.atttypmod) AS type FROM pg_attribute a
             JOIN pg_class c ON c.oid = a.attrelid
             WHERE c.relname = ? AND a.attname = 'crop' AND a.attnum > 0",
            [$table]
        )?->type;
    }

    private function partialBuilder(): VisualIndexBuilder
    {
        return \Mockery::mock(VisualIndexBuilder::class)->makePartial()
            ->shouldAllowMockingProtectedMethods();
    }

    private function succeed(VisualIndexBuilder $builder, ?callable $duringBuild = null): void
    {
        $builder->shouldReceive('runBuilder')->once()->andReturnUsing(function () use ($duringBuild) {
            if ($duringBuild !== null) {
                $duringBuild();
            }

            return ['added' => 1, 'skipped' => 0, 'generation' => 'generation-pg'];
        });
    }

    private function storedFeedback(Product $product, string $name, ?array $crop): \App\Models\SearchFeedback
    {
        Storage::fake('local');
        Storage::disk('local')->put($name, 'fake-bytes');
        $admin = \App\Models\User::factory()->create(['role' => 'admin']);

        return \App\Models\SearchFeedback::create([
            'user_id' => $admin->id, 'confirmed_product_id' => $product->id,
            'predicted_sku' => $product->sku, 'confirmed_sku' => $product->sku,
            'disk' => 'local', 'query_image_path' => $name,
            'photo_hash' => str_repeat('f', 64), 'dhash' => str_repeat('6', 16),
            'crop' => $crop, 'training_status' => 'verified', 'reference_eligible' => true,
        ]);
    }

    private bool $publicFakeReady = false;

    private function storedPhoto(Product $product, string $name, ?array $crop = null): \App\Models\ProductPhoto
    {
        if (! $this->publicFakeReady) {
            Storage::fake('public');
            $this->publicFakeReady = true;
        }
        Storage::disk('public')->put($name, 'fake-image-bytes');

        return $product->photos()->create([
            'path' => $name, 'disk' => 'public', 'index_status' => 'pending', 'crop' => $crop,
        ]);
    }

    public function test_schema_uses_json_crop_columns(): void
    {
        // Pins the incident shape: plain `json` (no `=` operator), not jsonb.
        $this->assertSame('json', $this->cropType('product_photos'));
        $this->assertSame('json', $this->cropType('search_feedback'));
    }

    public function test_unchanged_feedback_json_crop_completes_on_pgsql(): void
    {
        // The production incident shape: fractional crop, verified eligible.
        $product = Product::create(['sku' => 'PG-FB']);
        $row = $this->storedFeedback($product, 'verified-search/pg.webp', [
            'x' => 0.20833333333333, 'y' => 0.13533464566929,
            'width' => 0.63877952755906, 'height' => 0.55979330708661,
        ]);

        $builder = $this->partialBuilder();
        $this->succeed($builder);

        DB::enableQueryLog();
        try {
            $builder->appendPending();
        } finally {
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
        }

        // Must not throw `operator does not exist: json = unknown`.
        $this->assertNotNull($row->fresh()->indexed_at);
        $completion = null;
        foreach ($queries as $entry) {
            $sql = $entry['query'];
            if (str_contains($sql, 'search_feedback') && str_contains($sql, 'indexed_at')) {
                $completion = $sql;
            }
        }
        $this->assertNotNull($completion);
        $this->assertStringContainsString('::jsonb', $completion);
        $this->assertStringNotContainsString('"crop" = ?', $completion);
    }

    public function test_mutated_feedback_json_crop_rejected_on_pgsql(): void
    {
        $product = Product::create(['sku' => 'PG-FB-MUT']);
        $row = $this->storedFeedback($product, 'verified-search/pg-mut.webp', ['x' => 0.1, 'y' => 0.1, 'width' => 0.5, 'height' => 0.5]);

        $builder = $this->partialBuilder();
        $this->succeed($builder, function () use ($row) {
            \App\Models\SearchFeedback::whereKey($row->id)->update(['crop' => json_encode(['x' => 0.4, 'y' => 0.4, 'width' => 0.2, 'height' => 0.2])]);
        });
        $builder->appendPending();

        $this->assertNull($row->fresh()->indexed_at);
    }

    public function test_reordered_json_crop_matches_semantically_on_pgsql(): void
    {
        // jsonb equality is key-order insensitive, consistent with the
        // canonicalized PHP fingerprint. A rewrite with reordered keys is
        // the same application state and must complete.
        $product = Product::create(['sku' => 'PG-REORDER']);
        $row = $this->storedFeedback($product, 'verified-search/pg-re.webp', ['x' => 1, 'y' => 2, 'width' => 3, 'height' => 4]);

        $builder = $this->partialBuilder();
        $this->succeed($builder, function () use ($row) {
            \App\Models\SearchFeedback::whereKey($row->id)->update(['crop' => '{"height":4,"width":3,"y":2,"x":1}']);
        });
        $builder->appendPending();

        $this->assertNotNull($row->fresh()->indexed_at);
    }

    public function test_photo_json_crop_roundtrip_on_pgsql(): void
    {
        $product = Product::create(['sku' => 'PG-PHOTO']);
        $kept = $this->storedPhoto($product, 'products/pg-keep.jpg', ['x' => 0.1, 'y' => 0.1, 'width' => 0.5, 'height' => 0.5]);
        $changed = $this->storedPhoto($product, 'products/pg-chg.jpg', ['x' => 0.1, 'y' => 0.1, 'width' => 0.5, 'height' => 0.5]);

        $builder = $this->partialBuilder();
        $this->succeed($builder, function () use ($changed) {
            \App\Models\ProductPhoto::whereKey($changed->id)->update(['crop' => json_encode(['x' => 0.9, 'y' => 0.9, 'width' => 0.1, 'height' => 0.1])]);
        });
        $builder->appendPending();

        $this->assertSame('indexed', $kept->fresh()->index_status);
        $this->assertSame('indexing', $changed->fresh()->index_status);
    }

    public function test_null_crop_matches_on_pgsql(): void
    {
        $product = Product::create(['sku' => 'PG-NULL']);
        $photo = $this->storedPhoto($product, 'products/pg-null.jpg', null);

        $builder = $this->partialBuilder();
        $this->succeed($builder);
        $builder->appendPending();

        $this->assertSame('indexed', $photo->fresh()->index_status);
    }
}
