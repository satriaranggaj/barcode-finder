<?php

namespace App\Console\Commands;

use App\Services\VisualReferenceExporter;
use Illuminate\Console\Command;

class ExportVisualReferences extends Command
{
    protected $signature = 'products:export-visual {directory : New local dataset directory} {--include-verified : Include quality-checked confirmed search references}';

    protected $description = 'Stream reference masters and crop metadata to a private build dataset';

    public function handle(VisualReferenceExporter $exporter): int
    {
        $directory = $this->argument('directory');
        if (file_exists($directory)) {
            $this->error('Use a new directory; existing datasets are never overwritten.');

            return self::FAILURE;
        }
        // Mid-export failures bubble as RuntimeException (fail-closed, same
        // as before the shared exporter existed); the `.exporting` marker
        // left behind makes the Python builder refuse partial datasets.
        $count = $exporter->export($directory, (bool) $this->option('include-verified'));
        $this->info('Exported '.$count.' references. Build must succeed before publishing this dataset.');

        return self::SUCCESS;
    }
}
