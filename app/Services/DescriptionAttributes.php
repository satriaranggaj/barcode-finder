<?php

namespace App\Services;

/**
 * PHP mirror of ai-service/app/features/attributes.py RULES (v2).
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
        'drive' => ['drive', '/\b(PH[0-3]|PZ[0-3]|PHILLIPS|PHILIPS|CROSS|KEMBANG|PLUS|FLATHEAD|FLAT|SLOTTED|MINUS|TORX)\b/i'],
        'dimension' => ['dimension', '/\b\d+(?:[.,]\d+)?\s*[xX×*]\s*\d+(?:[.,]\d+)?(?:\s*[xX×*]\s*\d+(?:[.,]\d+)?)?\s*(?:MM|CM|M|INCH|IN|["″\'’])?/iu'],
        'measurement' => ['measurement', '/\b\d+(?:[.,]\d+)?\s*(?:MM|CM|M|INCH|IN)(?!\w)|\b\d+(?:[.,]\d+)?\s*["″\'’]/iu'],
        'length' => ['length', '/\b(PANJANG|PENDEK|LONG|SHORT)\b/i'],
        'size' => ['size', '/\b(?:SIZE|UKURAN|SZ)\s*[:=-]?\s*\d+(?:[.,]\d+)?\b/i'],
        'model' => ['model', '/\b[A-Z]{1,6}[-_]?\d{2,8}(?:[-_]\d{1,2}[A-Z]?)?[A-Z]?\b/i'],
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
                    'value' => self::normalizeForRule($rule, $raw),
                    'raw' => $raw,
                    'source' => self::SOURCE,
                    'rule' => $rule,
                ];
            }
        }

        return $records;
    }

    public static function normalizeForRule(string $rule, string $raw): string
    {
        return match ($rule) {
            'drive' => self::normalizeDrive($raw),
            'measurement' => self::normalizeMeasurement($raw),
            'dimension' => self::normalizeDimension($raw),
            'length' => self::normalizeLength($raw),
            default => self::normalize($raw),
        };
    }

    public static function normalizeDrive(string $raw): string
    {
        $token = self::normalize($raw);
        return match ($token) {
            'PLUS', 'PHILLIPS', 'PHILIPS', 'CROSS', 'KEMBANG' => 'PHILLIPS',
            'MINUS', 'FLAT', 'FLATHEAD', 'SLOTTED' => 'FLAT',
            default => $token,
        };
    }

    public static function normalizeMeasurement(string $raw): string
    {
        $token = self::normalize($raw);
        if (str_ends_with($token, "'") || str_ends_with($token, '’')) {
            return mb_substr($token, 0, mb_strlen($token) - 1).'"';
        }

        return $token;
    }

    public static function normalizeDimension(string $raw): string
    {
        $token = self::normalize($raw);
        $token = str_replace(['×', '*', '’'], ['X', 'X', '"'], $token);
        if (str_ends_with($token, "'")) {
            return mb_substr($token, 0, mb_strlen($token) - 1).'"';
        }

        return $token;
    }

    public static function normalizeLength(string $raw): string
    {
        $token = self::normalize($raw);
        return match ($token) {
            'PANJANG', 'LONG' => 'LONG',
            'PENDEK', 'SHORT' => 'SHORT',
            default => $token,
        };
    }

    public static function normalize(string $raw): string
    {
        return str_replace(',', '.', preg_replace('/\s+/', '', mb_strtoupper($raw)));
    }
}
