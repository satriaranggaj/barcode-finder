<?php

namespace App\Services;

/**
 * Retention planning for published FAISS generations and stale build dirs.
 *
 * Mirrors ai-service/app/scripts/retention.py (which runs automatically
 * after each successful publication under the builder lock). This PHP side
 * only PLANS and, on explicit request, DELETES — best-effort, never throws
 * for expected filesystem states. Serving/indexing continue regardless.
 */
final class IndexRetention
{
    public const GENERATION_PATTERN = '/^generation-[0-9a-f]{32}$/D';

    /**
     * @return array{root:?string, current:?string, current_readable:bool, keep:list<array{name:string,size_mb:float}>, remove:list<array{name:string,size_mb:float}>, builds:list<array{name:string,size_mb:float}>, builds_skipped:bool}
     */
    public static function plan(string $root, int $keep, int $graceSeconds, int $buildStaleHours): array
    {
        $plan = ['root' => $root, 'current' => null, 'current_readable' => false,
            'keep' => [], 'remove' => [], 'builds' => [], 'builds_skipped' => false];
        if (! is_dir($root) || is_link($root)) {
            return $plan;
        }
        $current = self::readCurrent($root);
        if ($current === null) {
            // Missing/corrupt CURRENT: fail closed, remove nothing.
            return $plan;
        }
        $plan['current'] = $current;
        $plan['current_readable'] = true;
        try {
            $entries = scandir($root);
        } catch (\Throwable) {
            return $plan;
        }
        if (! is_array($entries)) {
            return $plan;
        }
        $now = time();
        $generations = [];
        $buildNames = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $root.DIRECTORY_SEPARATOR.$entry;
            if (is_link($path)) {
                continue;
            }
            if (preg_match(self::GENERATION_PATTERN, $entry) === 1 && self::isSafeChild($root, $path) && is_dir($path)) {
                $modified = @filemtime($path);
                $generations[] = ['name' => $entry, 'mtime' => $modified === false ? 0 : (int) $modified];
            } elseif (str_starts_with($entry, 'build-') && self::isSafeChild($root, $path) && is_dir($path)) {
                $buildNames[] = $entry;
            }
        }
        usort($generations, fn ($a, $b) => $b['mtime'] <=> $a['mtime'] ?: strcmp($a['name'], $b['name']));
        $keepNames = [$current => true];
        if ($keep <= 0) {
            foreach ($generations as $generation) {
                $keepNames[$generation['name']] = true;
            }
        } else {
            foreach (array_slice($generations, 0, $keep) as $generation) {
                $keepNames[$generation['name']] = true;
            }
        }
        foreach ($generations as $generation) {
            if ($now - $generation['mtime'] < $graceSeconds) {
                $keepNames[$generation['name']] = true;
            }
        }
        foreach ($generations as $generation) {
            $row = ['name' => $generation['name'],
                'size_mb' => round(self::directorySize($root.DIRECTORY_SEPARATOR.$generation['name']) / 1048576, 1)];
            if (isset($keepNames[$generation['name']])) {
                $plan['keep'][] = $row;
            } else {
                $plan['remove'][] = $row;
            }
        }
        if (file_exists($root.DIRECTORY_SEPARATOR.'build.lock')) {
            // A build may be running: cannot prove any build-* dir is stale.
            $plan['builds_skipped'] = true;
        } elseif ($buildStaleHours > 0) {
            $cutoff = $now - $buildStaleHours * 3600;
            foreach ($buildNames as $name) {
                $path = $root.DIRECTORY_SEPARATOR.$name;
                $modified = @filemtime($path);
                if ($modified !== false && (int) $modified > $cutoff) {
                    continue;
                }
                $plan['builds'][] = ['name' => $name, 'size_mb' => round(self::directorySize($path) / 1048576, 1)];
            }
        }

        return $plan;
    }

    public static function readCurrent(string $root): ?string
    {
        try {
            $content = @file_get_contents($root.DIRECTORY_SEPARATOR.'CURRENT');
        } catch (\Throwable) {
            return null;
        }
        if (! is_string($content)) {
            return null;
        }
        $name = trim($content);

        return preg_match(self::GENERATION_PATTERN, $name) === 1 ? $name : null;
    }

    /**
     * Delete one planned generation/build directory. Validates everything
     * again; returns false (never throws) when unsafe or unremovable.
     */
    public static function removePlanned(string $root, string $name): bool
    {
        try {
            if (preg_match(self::GENERATION_PATTERN, $name) !== 1 && ! str_starts_with($name, 'build-')) {
                return false;
            }
            if (str_contains($name, '/') || str_contains($name, '\\') || $name === '.' || $name === '..') {
                return false;
            }
            $path = $root.DIRECTORY_SEPARATOR.$name;
            if (is_link($path) || ! is_dir($path) || ! self::isSafeChild($root, $path)) {
                return false;
            }
            if ($name === self::readCurrent($root)) {
                return false;
            }
            self::removeTree($path);
        } catch (\Throwable $error) {
            report($error);

            return false;
        }

        return ! is_dir($root.DIRECTORY_SEPARATOR.$name);
    }

    private static function isSafeChild(string $root, string $path): bool
    {
        $realRoot = realpath($root);
        $real = realpath($path);

        return $realRoot !== false && $real !== false && dirname($real) === $realRoot;
    }

    private static function directorySize(string $directory): int
    {
        $total = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
        } catch (\Throwable) {
            return 0;
        }
        foreach ($iterator as $file) {
            try {
                if (! $file->isLink() && $file->isFile()) {
                    $total += $file->getSize();
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $total;
    }

    private static function removeTree(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            try {
                if ($file->isLink()) {
                    continue;
                }
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            } catch (\Throwable) {
                continue;
            }
        }
        rmdir($directory);
    }
}
