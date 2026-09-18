<?php

namespace App\Console\Commands;

use App\Services\CropCoordinates;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class BuildTrainingDataset extends Command
{
    public const MANIFEST_SCHEMA = 'training-dataset-v1';

    protected $signature = 'search:build-dataset {input : Verified training JSONL from search:export-training}'
        .' {output : New dataset directory}'
        .' {--seed=42 : Deterministic split seed}'
        .' {--val=0.15 : Validation fraction 0..0.5}'
        .' {--test=0.15 : Test fraction 0..0.5}';

    protected $description = 'Split verified samples into train/val/test with a reproducible manifest; never trains a model';

    public function handle(): int
    {
        $seed = (string) $this->option('seed');
        $val = (float) $this->option('val');
        $test = (float) $this->option('test');
        if ($seed === '' || ! is_finite($val) || ! is_finite($test) || $val < 0 || $val > 0.5 || $test < 0 || $test > 0.5 || $val + $test >= 1) {
            $this->error('Invalid split config: seed required, val/test in 0..0.5 with val+test < 1.');

            return self::FAILURE;
        }
        $input = $this->argument('input');
        if (! is_file($input)) {
            $this->error('Input JSONL not found.');

            return self::FAILURE;
        }
        $output = $this->argument('output');
        if (file_exists($output) || ! mkdir($output, 0700, true)) {
            $this->error('Use a new directory; existing datasets are never overwritten.');

            return self::FAILURE;
        }
        try {
            $inputHash = hash_file('sha256', $input);
            $samples = $this->readSamples($input);
            $groups = $this->group($samples);
            $splitOf = $this->assign($groups, $seed, $val, $test);
            // Keep only identities/groups in memory, never all candidate JSON
            // and query evidence for a 100k-sample export.
            $counts = $this->writeSplits($input, $output, $splitOf);
            if ($inputHash !== hash_file('sha256', $input)) {
                throw new \RuntimeException('Input changed during dataset export; discard this incomplete directory.');
            }
            $manifest = [
                'manifest_schema' => self::MANIFEST_SCHEMA,
                'created_at' => now()->toIso8601String(),
                'seed' => $seed, 'val_fraction' => $val, 'test_fraction' => $test,
                'source_filter' => 'verified-only',
                'source_sha256' => $inputHash,
                'counts' => $counts,
                'groups' => $this->manifestGroups($groups, $splitOf),
            ];
            file_put_contents($output.DIRECTORY_SEPARATOR.'manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } catch (\Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        $this->info("Dataset ready: train={$counts['train']} validation={$counts['validation']} test={$counts['test']} (seed {$seed}).");

        return self::SUCCESS;
    }

    /**
     * Validate every sample and confirm the anchor image exists. Verified-only
     * is enforced by shape: rows without staff confirmation fields are rejected.
     *
     * @return list<array{group: string, photo_hash: string}>
     */
    private function readSamples(string $input): array
    {
        $samples = [];
        $hashes = [];
        $identities = [];
        $inputFile = new \SplFileObject($input);
        foreach ($inputFile as $number => $line) {
            if (trim($line) === '') {
                continue;
            }
            $record = json_decode($line, true);
            $lineNo = $number + 1;
            if (! is_array($record)) {
                throw new \RuntimeException("Invalid JSON on line {$lineNo}.");
            }
            foreach (['positive_sku', 'photo_hash', 'verified_by', 'verified_at'] as $key) {
                if (! isset($record[$key]) || $record[$key] === '') {
                    throw new \RuntimeException("Line {$lineNo} is not a verified sample: missing {$key}.");
                }
            }
            $anchor = $record['anchor'] ?? null;
            if (! is_array($anchor) || ! is_string($anchor['path'] ?? null) || ! is_string($anchor['disk'] ?? null)) {
                throw new \RuntimeException("Line {$lineNo} has an invalid anchor reference.");
            }
            if (! is_string($record['positive_sku']) || trim($record['positive_sku']) === ''
                || ! is_string($record['photo_hash']) || ! preg_match('/^[a-fA-F0-9]{64}$/D', $record['photo_hash'])
                || $anchor['path'] === '' || str_contains($anchor['path'], '..')
                || str_starts_with($anchor['path'], '/') || str_contains($anchor['path'], ':')
                || ($record['hard_negative_sku'] ?? null) === $record['positive_sku']) {
                throw new \RuntimeException("Line {$lineNo} has an invalid label or image identity.");
            }
            CropCoordinates::validate($anchor['crop'] ?? null);
            try {
                $exists = Storage::disk($anchor['disk'])->exists($anchor['path']);
            } catch (\Throwable) {
                throw new \RuntimeException("Line {$lineNo} references an unknown disk.");
            }
            if (! $exists) {
                throw new \RuntimeException("Line {$lineNo} references a missing image.");
            }
            $hash = (string) $record['photo_hash'];
            $hashes[$hash] = ($hashes[$hash] ?? 0) + 1;
            // Group key keeps one capture session (or one photo) in one split,
            // so the same image can never land in both train and evaluation.
            $group = $record['capture_group'] ?? $hash;
            $group = is_string($group) && $group !== '' ? $group : $hash;
            // A conflicting capture label must not override photo identity.
            // Reject rather than silently rewriting a staff-provided session.
            foreach (['hash:'.strtolower($hash), 'path:'.$anchor['disk'].':'.$anchor['path']] as $identity) {
                if (isset($identities[$identity]) && $identities[$identity] !== $group) {
                    throw new \RuntimeException("Line {$lineNo} has duplicate image identity across capture groups.");
                }
                $identities[$identity] = $group;
            }
            $samples[] = ['group' => $group, 'photo_hash' => $hash];
        }
        if ($samples === []) {
            throw new \RuntimeException('Input contains no samples.');
        }
        foreach ($hashes as $hash => $count) {
            if ($count > 1) {
                $this->warn("Photo {$hash} appears {$count} times; duplicates stay in one split.");
            }
        }

        return $samples;
    }

    /** @return array<string, list<string>> group key => photo hashes */
    private function group(array $samples): array
    {
        $groups = [];
        foreach ($samples as $sample) {
            $groups[$sample['group']][] = $sample['photo_hash'];
        }
        ksort($groups);

        return $groups;
    }

    /** Deterministic hash-bucket assignment; identical input+seed always agrees. */
    private function assign(array $groups, string $seed, float $val, float $test): array
    {
        $splitOf = [];
        foreach (array_keys($groups) as $group) {
            $bucket = hexdec(substr(hash('sha256', $seed."\n".$group), 0, 8)) / 0xFFFFFFFF;
            $splitOf[$group] = $bucket < $test ? 'test' : ($bucket < $test + $val ? 'validation' : 'train');
        }

        return $splitOf;
    }

    private function writeSplits(string $input, string $output, array $splitOf): array
    {
        $handles = [];
        $counts = ['train' => 0, 'validation' => 0, 'test' => 0];
        try {
            foreach ($counts as $split => $_) {
                $handles[$split] = fopen($output.DIRECTORY_SEPARATOR.$split.'.jsonl', 'xb');
                if (! $handles[$split]) {
                    throw new \RuntimeException('Cannot write dataset split.');
                }
            }
            foreach (new \SplFileObject($input) as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                $group = $record['capture_group'] ?? null;
                $group = is_string($group) && $group !== '' ? $group : $record['photo_hash'];
                $split = $splitOf[$group];
                $encoded = json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL;
                if (fwrite($handles[$split], $encoded) !== strlen($encoded)) {
                    throw new \RuntimeException('Incomplete dataset split.');
                }
                $counts[$split]++;
            }
        } finally {
            foreach ($handles as $handle) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
        }

        return $counts;
    }

    private function manifestGroups(array $groups, array $splitOf): array
    {
        $manifest = ['train' => [], 'validation' => [], 'test' => []];
        foreach ($groups as $group => $hashes) {
            $manifest[$splitOf[$group]][] = ['group' => $group, 'photo_hashes' => array_values(array_unique($hashes))];
        }

        return $manifest;
    }
}
