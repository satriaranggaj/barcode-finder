<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class TrainingDatasetSplitTest extends TestCase
{
    use RefreshDatabase;

    private function writeInput(string $name, array $records): string
    {
        Storage::disk('local')->put($name, implode("\n", array_map(
            fn ($record) => json_encode($record, JSON_THROW_ON_ERROR), $records))."\n");

        return Storage::disk('local')->path($name);
    }

    private function sample(string $hash, string $group, string $sku = 'SKU-A'): array
    {
        Storage::disk('local')->put("verified-search/{$hash}.webp", 'bytes');

        return [
            'dataset_schema' => 'training-jsonl-v1',
            'anchor' => ['disk' => 'local', 'path' => "verified-search/{$hash}.webp", 'crop' => null],
            'positive_sku' => $sku, 'hard_negative_sku' => null, 'photo_hash' => $hash,
            'capture_group' => $group, 'source' => 'confirmed_search',
            'verified_by' => 1, 'verified_at' => now()->toIso8601String(),
        ];
    }

    private function freshDir(): string
    {
        return sys_get_temp_dir().DIRECTORY_SEPARATOR.'lensku-dataset-'.Str::uuid();
    }

    private function manifest(string $dir): array
    {
        return json_decode(file_get_contents($dir.'/manifest.json'), true);
    }

    private function splitHashes(string $dir, string $split): array
    {
        $rows = array_values(array_filter(explode("\n", file_get_contents("{$dir}/{$split}.jsonl"))));

        return array_map(fn ($line) => json_decode($line, true)['photo_hash'], $rows);
    }

    public function test_verified_only_split_with_manifest(): void
    {
        Storage::fake('local');
        $records = [];
        for ($i = 0; $i < 20; $i++) {
            $records[] = $this->sample(hash('sha256', "photo-{$i}"), 'group-'.$i, 'SKU-'.($i % 4));
        }
        $input = $this->writeInput('samples.jsonl', $records);
        $dir = $this->freshDir();

        $this->artisan('search:build-dataset', ['input' => $input, 'output' => $dir, '--seed' => '7'])
            ->assertSuccessful();

        $manifest = $this->manifest($dir);
        $this->assertSame('training-dataset-v1', $manifest['manifest_schema']);
        $this->assertSame('7', $manifest['seed']);
        $this->assertSame('verified-only', $manifest['source_filter']);
        $this->assertSame(20, array_sum($manifest['counts']));
        // Every sample carries its reproducible identity.
        foreach (['train', 'validation', 'test'] as $split) {
            foreach ($this->splitHashes($dir, $split) as $hash) {
                $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
            }
        }
        // No photo hash appears in more than one split.
        $all = array_merge($this->splitHashes($dir, 'train'), $this->splitHashes($dir, 'validation'), $this->splitHashes($dir, 'test'));
        $this->assertSame(count($all), count(array_unique($all)));
    }

    public function test_split_is_deterministic_for_same_seed(): void
    {
        Storage::fake('local');
        $records = [];
        for ($i = 0; $i < 12; $i++) {
            $records[] = $this->sample(hash('sha256', "photo-{$i}"), 'group-'.$i);
        }
        $input = $this->writeInput('samples.jsonl', $records);

        $first = $this->freshDir();
        $second = $this->freshDir();
        $this->artisan('search:build-dataset', ['input' => $input, 'output' => $first, '--seed' => '99'])->assertSuccessful();
        $this->artisan('search:build-dataset', ['input' => $input, 'output' => $second, '--seed' => '99'])->assertSuccessful();

        foreach (['train', 'validation', 'test'] as $split) {
            $this->assertSame($this->splitHashes($first, $split), $this->splitHashes($second, $split));
        }
        $this->assertSame($this->manifest($first)['groups'], $this->manifest($second)['groups']);
    }

    public function test_duplicate_photo_never_leaks_across_splits(): void
    {
        Storage::fake('local');
        $records = [$this->sample(str_repeat('a', 64), 'group-dup'), $this->sample(str_repeat('a', 64), 'group-dup')];
        for ($i = 0; $i < 6; $i++) {
            $records[] = $this->sample(hash('sha256', "other-{$i}"), 'group-'.$i);
        }
        $input = $this->writeInput('samples.jsonl', $records);
        $dir = $this->freshDir();

        $this->artisan('search:build-dataset', ['input' => $input, 'output' => $dir])->assertSuccessful();

        $locations = [];
        foreach (['train', 'validation', 'test'] as $split) {
            foreach ($this->splitHashes($dir, $split) as $hash) {
                $locations[$hash][] = $split;
            }
        }
        $this->assertCount(2, $locations[str_repeat('a', 64)]);
        foreach ($locations as $splits) {
            $this->assertSame(1, count(array_unique($splits)));
        }
    }

    public function test_missing_image_fails_closed(): void
    {
        Storage::fake('local');
        $records = [$this->sample(str_repeat('a', 64), 'group-1')];
        Storage::disk('local')->delete('verified-search/'.str_repeat('a', 64).'.webp');
        $input = $this->writeInput('samples.jsonl', $records);

        $this->artisan('search:build-dataset', ['input' => $input, 'output' => $this->freshDir()])
            ->assertFailed();
    }

    public function test_duplicate_identity_with_conflicting_groups_is_rejected(): void
    {
        Storage::fake('local');
        $first = $this->sample(str_repeat('a', 64), 'session-one');
        foreach ([
            array_replace($first, ['capture_group' => 'session-two']),
            array_replace($first, ['capture_group' => 'session-two', 'photo_hash' => str_repeat('b', 64)]),
        ] as $i => $second) {
            $input = $this->writeInput("conflict-{$i}.jsonl", [$first, $second]);
            $dir = $this->freshDir();
            $this->artisan('search:build-dataset', ['input' => $input, 'output' => $dir])->assertFailed();
            $this->assertFileDoesNotExist($dir.'/manifest.json');
        }
    }

    public function test_invalid_and_unverified_samples_fail_closed(): void
    {
        Storage::fake('local');
        $good = $this->sample(str_repeat('a', 64), 'group-1');
        foreach ([
            'not json',
            json_encode(['positive_sku' => 'SKU-A']),
            json_encode(array_merge($good, ['anchor' => ['disk' => 'local']])),
            json_encode(array_merge($good, ['verified_by' => null])),
        ] as $i => $line) {
            Storage::disk('local')->put("bad-{$i}.jsonl", $line."\n");
            $this->artisan('search:build-dataset', [
                'input' => Storage::disk('local')->path("bad-{$i}.jsonl"), 'output' => $this->freshDir(),
            ])->assertFailed();
        }
    }

    public function test_existing_directory_is_never_overwritten(): void
    {
        Storage::fake('local');
        $input = $this->writeInput('samples.jsonl', [$this->sample(str_repeat('a', 64), 'group-1')]);
        $dir = $this->freshDir();
        mkdir($dir, 0700, true);

        $this->artisan('search:build-dataset', ['input' => $input, 'output' => $dir])->assertFailed();
        $this->assertFileDoesNotExist($dir.'/manifest.json');
    }
}
