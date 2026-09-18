<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CheckVisualSearch extends Command
{
    protected $signature = 'search:check';

    protected $description = 'Check AI readiness for the primary FAISS /search driver';

    public function handle(): int
    {
        try {
            $data = Http::connectTimeout(3)->timeout(10)->get(rtrim(config('services.ai.url'), '/').'/health')->throw()->json();
            $this->line(json_encode($data, JSON_PRETTY_PRINT));
            if (($data['status'] ?? '') !== 'ok' || ($data['references'] ?? 0) < 1) {
                $this->error('Build a compatible nonempty index and restart the AI service first.');

                return self::FAILURE;
            }
            $this->info('AI ready. FAISS /search is the primary driver; refresh Laravel config if it was cached.');

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
