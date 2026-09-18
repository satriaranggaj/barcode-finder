<?php

namespace App\Services;

use App\Models\SearchFeedback;

/**
 * Exact and near-duplicate protection for confirmed_search references.
 *
 * Exact duplicates use the normalized content hash (decoded pixels, so
 * re-encoded copies of the same photo still match). Near duplicates use the
 * perceptual dhash within the same confirmed SKU only — similarity is never
 * a universal truth, so the Hamming threshold is configurable and scoped
 * narrowly. Nothing is ever deleted based on similarity: exact matches are
 * rejected with a reason, near matches are stored but flagged ineligible
 * as references.
 */
final class DuplicateGuard
{
    public const EXACT = 'exact_duplicate';

    public const NEAR = 'near_duplicate';

    public const UNIQUE = 'unique';

    /**
     * Hamming distance between two 16-hex-char perceptual hashes.
     */
    public static function hammingDistance(string $a, string $b): int
    {
        foreach ([$a, $b] as $hash) {
            if (! preg_match('/^[a-f0-9]{16}$/D', $hash)) {
                throw new \InvalidArgumentException('Perceptual hash must be 16 hex characters.');
            }
        }
        $distance = 0;
        foreach (str_split($a, 2) as $i => $hex) {
            $distance += substr_count(decbin(hexdec($hex) ^ hexdec(substr($b, $i * 2, 2))), '1');
        }

        return $distance;
    }

    /**
     * @return array{status: string, distance: ?int, duplicate_of: ?int}
     */
    public static function check(string $photoHash, string $dhash, int $productId): array
    {
        $exact = SearchFeedback::where('photo_hash', $photoHash)->first(['id']);
        if ($exact) {
            return ['status' => self::EXACT, 'distance' => 0, 'duplicate_of' => $exact->id];
        }
        $threshold = max(0, (int) config('retrieval.reference_max_dhash_distance', 4));
        $closest = null;
        foreach (SearchFeedback::where('confirmed_product_id', $productId)->select(['id', 'dhash'])->lazyById(100) as $other) {
            $distance = self::hammingDistance($dhash, $other->dhash);
            if ($closest === null || $distance < $closest['distance']) {
                $closest = ['distance' => $distance, 'duplicate_of' => $other->id];
            }
            if ($distance <= $threshold) {
                break;
            }
        }
        if ($closest !== null && $closest['distance'] <= $threshold) {
            return ['status' => self::NEAR, 'distance' => $closest['distance'], 'duplicate_of' => $closest['duplicate_of']];
        }

        return ['status' => self::UNIQUE, 'distance' => $closest['distance'] ?? null, 'duplicate_of' => null];
    }
}
