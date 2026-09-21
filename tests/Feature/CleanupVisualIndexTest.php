<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CleanupVisualIndexTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lensku-cleanup-'.Str::uuid();
        mkdir($this->root, 0700, true);
        config(['retrieval.index_path' => $this->root]);
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && is_dir($this->root) && ! is_link($this->root)) {
            collect(new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            ))->each(function ($file): void {
                try {
                    if ($file->isLink()) {
                        return;
                    }
                    $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
                } catch (\Throwable) {
                }
            });
            try {
                rmdir($this->root);
            } catch (\Throwable) {
            }
        }
        parent::tearDown();
    }

    private function gen(string $tag, int $bytes = 2048, int $ageSeconds = 0): string
    {
        $name = 'generation-'.str_repeat($tag, 32);
        $dir = $this->root.DIRECTORY_SEPARATOR.$name;
        mkdir($dir, 0700, true);
        file_put_contents($dir.DIRECTORY_SEPARATOR.'payload.faiss', str_repeat('x', $bytes));
        touch($dir, time() - $ageSeconds);

        return $name;
    }

    public function test_dry_run_deletes_nothing_and_lists_candidates(): void
    {
        $old1 = $this->gen('1', ageSeconds: 100000);
        $old2 = $this->gen('2', ageSeconds: 90000);
        $mid = $this->gen('3', ageSeconds: 80000);
        $current = $this->gen('4', ageSeconds: 10);
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'CURRENT', $current);

        $this->artisan('search:cleanup-index', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Current generation:')
            ->expectsOutputToContain($current)
            ->expectsOutputToContain($old1)
            ->expectsOutputToContain('Dry-run: nothing was modified.');

        foreach ([$old1, $old2, $mid, $current] as $name) {
            $this->assertDirectoryExists($this->root.DIRECTORY_SEPARATOR.$name);
        }
    }

    public function test_force_cleanup_keeps_current_newest_and_grace(): void
    {
        config(['retrieval.index_generations_keep' => 1, 'retrieval.index_generation_grace_seconds' => 3600]);
        $old1 = $this->gen('1', ageSeconds: 100000);
        $old2 = $this->gen('2', ageSeconds: 90000);
        $mid = $this->gen('3', ageSeconds: 80000);
        $young = $this->gen('5', ageSeconds: 100);
        $current = $this->gen('4', ageSeconds: 10);
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'CURRENT', $current);
        mkdir($this->root.DIRECTORY_SEPARATOR.'random-dir', 0700, true);

        $this->artisan('search:cleanup-index', ['--force' => true])->assertSuccessful();

        // keep=1 newest (current) + grace (young, 100s < 3600s): the rest gone.
        $this->assertDirectoryDoesNotExist($this->root.DIRECTORY_SEPARATOR.$old1);
        $this->assertDirectoryDoesNotExist($this->root.DIRECTORY_SEPARATOR.$old2);
        $this->assertDirectoryDoesNotExist($this->root.DIRECTORY_SEPARATOR.$mid);
        $this->assertDirectoryExists($this->root.DIRECTORY_SEPARATOR.$young);
        $this->assertDirectoryExists($this->root.DIRECTORY_SEPARATOR.$current);
        $this->assertDirectoryExists($this->root.DIRECTORY_SEPARATOR.'random-dir');
        $this->assertSame($current, trim(file_get_contents($this->root.DIRECTORY_SEPARATOR.'CURRENT')));
    }

    public function test_missing_current_removes_nothing(): void
    {
        $old = $this->gen('1', ageSeconds: 100000);

        $this->artisan('search:cleanup-index', ['--dry-run' => true])->assertSuccessful();
        $this->artisan('search:cleanup-index', ['--force' => true])->assertSuccessful();

        $this->assertDirectoryExists($this->root.DIRECTORY_SEPARATOR.$old);
    }

    public function test_build_lock_skips_build_dirs_but_generations_cleaned(): void
    {
        config(['retrieval.index_generations_keep' => 1, 'retrieval.index_build_stale_hours' => 24]);
        $old = $this->gen('1', ageSeconds: 100000);
        $current = $this->gen('2', ageSeconds: 10);
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'CURRENT', $current);
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'build.lock', '1234');
        $stale = $this->root.DIRECTORY_SEPARATOR.'build-abcdef12';
        mkdir($stale, 0700, true);
        touch($stale, time() - 100000);

        $this->artisan('search:cleanup-index', ['--force' => true])->assertSuccessful();

        $this->assertDirectoryDoesNotExist($this->root.DIRECTORY_SEPARATOR.$old);
        $this->assertDirectoryExists($stale);
    }

    public function test_stale_build_dirs_removed_without_lock(): void
    {
        $current = $this->gen('c', ageSeconds: 10);
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'CURRENT', $current);
        $stale = $this->root.DIRECTORY_SEPARATOR.'build-abcdef12';
        mkdir($stale, 0700, true);
        file_put_contents($stale.DIRECTORY_SEPARATOR.'x', 'x');
        touch($stale, time() - 100000);
        $fresh = $this->root.DIRECTORY_SEPARATOR.'build-12345678';
        mkdir($fresh, 0700, true);

        $this->artisan('search:cleanup-index', ['--force' => true])->assertSuccessful();

        $this->assertDirectoryDoesNotExist($stale);
        $this->assertDirectoryExists($fresh);
    }

    public function test_symlink_generation_never_deleted(): void
    {
        $outside = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lensku-cleanup-outside-'.Str::uuid();
        mkdir($outside, 0700, true);
        file_put_contents($outside.DIRECTORY_SEPARATOR.'secret.faiss', 'secret');
        try {
            $linked = $this->root.DIRECTORY_SEPARATOR.'generation-'.str_repeat('d', 32);
            if (! @symlink($outside, $linked)) {
                $this->markTestSkipped('symlinks unavailable');
            }
        } finally {
            // tearDown below removes $outside only when created here.
        }
        $current = $this->gen('c', ageSeconds: 10);
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'CURRENT', $current);

        try {
            $this->artisan('search:cleanup-index', ['--force' => true])->assertSuccessful();

            $this->assertTrue(is_link($linked));
            $this->assertFileExists($outside.DIRECTORY_SEPARATOR.'secret.faiss');
        } finally {
            if (is_link($linked ?? '')) {
                unlink($linked);
            }
            @unlink($outside.DIRECTORY_SEPARATOR.'secret.faiss');
            @rmdir($outside);
        }
    }

    public function test_private_workspace_retention_old_removed_recent_kept(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $old = '11111111-2222-3333-4444-555555555555';
        $fresh = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $disk->makeDirectory("index-builds/{$old}");
        $disk->put("index-builds/{$old}/x.txt", 'old');
        touch($disk->path("index-builds/{$old}"), time() - 4 * 86400);
        $disk->makeDirectory("index-builds/{$fresh}");
        $disk->put("index-builds/{$fresh}/x.txt", 'new');

        $this->artisan('search:cleanup-index', ['--force' => true])->assertSuccessful();

        $this->assertFalse($disk->exists("index-builds/{$old}"));
        $this->assertTrue($disk->exists("index-builds/{$fresh}/x.txt"));
    }
}
