<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SearchEvidence
{
    public function stage(array $report, int $user, ?string $captureGroup = null, ?string $selectionSource = null): ?string
    {
        if ($selectionSource !== null && ! in_array($selectionSource, CropCoordinates::SELECTION_MODES, true)) {
            return null;
        }

        $evidence = $report['feedback'] ?? null;
        if (! is_array($evidence)) {
            return null;
        }
        if (! preg_match('/^[a-f0-9]{64}$/D', $evidence['photo_hash'] ?? '')
            || ! preg_match('/^[a-f0-9]{16}$/D', $evidence['dhash'] ?? '')
            || ! is_numeric($evidence['blur'] ?? null) || ! is_finite((float) $evidence['blur'])
            || ! is_numeric($evidence['min_side'] ?? null)) {
            return null;
        }
        // Optional newer metrics fail closed when present but malformed.
        foreach (['gray_std', 'crop_pixels'] as $metric) {
            if (array_key_exists($metric, $evidence) && $evidence[$metric] !== null
                && (! is_numeric($evidence[$metric]) || ! is_finite((float) $evidence[$metric]))) {
                return null;
            }
        }
        $bytes = base64_decode($evidence['master'] ?? '', true);
        if (! $bytes || @getimagesizefromstring($bytes) === false) {
            return null;
        }
        unset($evidence['master']);
        $token = (string) Str::uuid();
        // Transient query photos stay on the app server and are pruned within
        // minutes; only human-confirmed references move to object storage.
        $disk = config('retrieval.query_temp_disk');
        if ($disk === 'public') {
            return null;
        }
        $path = app(StoragePaths::class)->pendingQuery();
        try {
            if (! Storage::disk($disk)->put($path, $bytes, ['visibility' => 'private'])) {
                return null;
            }
            $cached = Cache::put('search-evidence:'.$token, ['user_id' => $user, 'disk' => $disk, 'path' => $path,
                'capture_group' => $captureGroup ?? 'unknown-session',
                'selection_source' => $selectionSource,
                'evidence' => $evidence, 'crop' => $report['query']['selection_used'] ?? null,
                'results' => $report['results'], 'confidence' => $report['confidence'] ?? 'low',
                'score_gap' => $report['score_gap'] ?? null, 'query' => $report['query'] ?? [],
            ], now()->addMinutes(15));
            if (! $cached) {
                Storage::disk($disk)->delete($path);

                return null;
            }

            return $token;
        } catch (\Throwable $error) {
            try {
                Storage::disk($disk)->delete($path);
            } catch (\Throwable) {
            }
            report($error);

            return null;
        }
    }
}
