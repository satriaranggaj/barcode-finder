<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneSearchImages extends Command
{
    protected $signature = 'search:prune-pending';

    protected $description = 'Remove only expired unconfirmed query images after 15 minutes';

    public function handle(): int
    {
        $disk = Storage::disk(config('retrieval.query_temp_disk'));
        try {
            $entries = $disk->getDriver()->listContents('search-pending', false);
        } catch (\Throwable $error) {
            report($error);

            return self::FAILURE;
        }
        foreach ($entries as $entry) {
            if ($entry->isFile() && ($entry->lastModified() ?? time()) < time() - 900) {
                // One stubborn object must not block the rest of the sweep.
                try {
                    $disk->delete($entry->path());
                } catch (\Throwable $error) {
                    report($error);
                }
            }
        }

        return self::SUCCESS;
    }
}
