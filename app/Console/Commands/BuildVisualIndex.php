<?php

namespace App\Console\Commands;

use App\Services\StoragePaths;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class BuildVisualIndex extends Command
{
    protected $signature = 'search:build-index {--include-verified : Include eligible human-confirmed references}';

    protected $description = 'Export a consistent reference dataset and publish a complete FAISS generation';

    public function handle(): int
    {
        $lock = Cache::lock('visual-index-build', 86400);
        if (! $lock->get()) {
            $this->error('Another build is running.');

            return self::FAILURE;
        }
        $dataset = Storage::disk('local')->path(app(StoragePaths::class)->indexBuild());
        try {
            if ($this->call('products:export-visual', ['directory' => $dataset, '--include-verified' => $this->option('include-verified')]) !== 0) {
                return self::FAILURE;
            }
            $process = new Process([config('retrieval.python'), '-B', '-m', 'app.scripts.build_index', '--dataset', $dataset, '--rebuild'], base_path('ai-service'), ['FAISS_INDEX_PATH' => config('retrieval.index_path'), 'RELEVANCE_POLICY' => '']);
            $process->setTimeout(null); // CLI batch job; HTTP timeouts remain bounded.
            $process->run(fn ($type, $buffer) => $this->output->write($buffer));
            if (! $process->isSuccessful()) {
                $this->error('Build failed; serving generation was not replaced. Dataset retained: '.$dataset);

                return self::FAILURE;
            }
            $this->info('Generation published. Restart the AI worker to load it; previous workers keep their snapshot.');
            $this->line('Private export: '.$dataset);
            // Timestamp for the admin "awaiting index" nudge; failures above
            // return before this line, so it only marks successful builds.
            Cache::forever('visual-index-built-at', now()->toIso8601String());

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
