<?php

namespace App\Services;

/**
 * PHP mirror of ai-service/app/features/attributes.py RULES.
 *
 * Parses structured attributes from product descriptions as SECONDARY
 * evidence scaffolding only; never used in ranking. Patterns, value
 * normalization and the cue-required policy (bare numbers are ambiguous
 * and never guessed) intentionally match the Python registry; the Python
 * side remains canonical for retrieval and any drift is caught by mirrored
 * fixtures in tests/Unit/DescriptionAttributesTest.
 */
final class DescriptionAttributes
{
    public const SOURCE = 'description_parser';

    public const MAX_TEXT = 4000;

    /** Rule id => [key, PCRE pattern]. Order is stable for deterministic output. */
    public const RULES = [
        'drive' => ['drive', '/\b(PH[123]|PHILLIPS|FLAT|SLOTTED)\b/i'],
        'measurement' => ['measurement', '/\b\d+(?:[.,]\d+)?\s*(?:MM|CM|M|INCH|IN)(?!\w)|\b\d+(?:[.,]\d+)?\s*["″]/i'],
        'size' => ['size', '/\b(?:SIZE|UKURAN|SZ)\s*[:=-]?\s*\d+(?:[.,]\d+)?\b/i'],
        'model' => ['model', '/\b[A-Z]{1,6}[-]?\d{2,8}[A-Z]?\b/i'],
        'quantity' => ['quantity', '/\b\d+\s*(?:PCS|PC|PACK|SET|PAIR)\b/i'],
        'color' => ['color', '/\b(?:RED|BLUE|GREEN|BLACK|WHITE|YELLOW|MERAH|BIRU|HIJAU|HITAM|PUTIH|KUNING)\b/i'],
    ];

    /**
     * @return list<array{key: string, value: string, raw: string, source: string, rule: string}>
     */
    public static function parse(?string $description): array
    {
        $text = mb_substr((string) ($description ?? ''), 0, self::MAX_TEXT);
        if (trim($text) === '') {
            return [];
        }
        $records = [];
        foreach (self::RULES as $rule => [$key, $pattern]) {
            if (preg_match_all($pattern, $text, $matches) === false) {
                continue;
            }
            foreach ($matches[0] as $raw) {
                $records[] = [
                    'key' => $key,
                    'value' => self::normalize($raw),
                    'raw' => $raw,
                    'source' => self::SOURCE,
                    'rule' => $rule,
                ];
            }
        }

        return $records;
    }

    public static function normalize(string $raw): string
    {
        return str_replace(',', '.', preg_replace('/\s+/', '', mb_strtoupper($raw)));
    }
}
