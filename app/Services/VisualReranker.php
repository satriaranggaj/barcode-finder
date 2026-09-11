<?php

namespace App\Services;

class VisualReranker
{
    public function score(array $query, array $reference, float $global, array $weights): float
    {
        $sum = 0.0;
        $total = 0.0;
        foreach ($weights as $name => $weight) {
            if (! is_numeric($weight) || ! is_finite((float) $weight) || $weight < 0) {
                throw new \InvalidArgumentException('Reranking weights must be finite and nonnegative.');
            }
            if (! $weight) {
                continue;
            }
            $score = null;
            if ($name === 'global') {
                $score = $global;
            } elseif (($query['version'] ?? null) === 'visual-v1' && ($reference['version'] ?? null) === 'visual-v1') {
                $a = $query[$name] ?? null;
                $b = $reference[$name] ?? null;
                if ($a !== null && $b !== null) {
                    $score = match ($name) {
                        'shape', 'color', 'texture' => $this->histogram($a, $b),
                        'proportion' => is_numeric($a) && is_numeric($b) && $a > 0 && $b > 0 ? min($a / $b, $b / $a) : null,
                        'local' => $this->local($a, $b),
                        default => null,
                    };
                }
            }
            // Missing descriptors never count as a zero similarity penalty.
            if ($score !== null) {
                $sum += $weight * $score;
                $total += $weight;
            }
        }

        return $total ? $sum / $total : $global;
    }

    private function histogram(mixed $left, mixed $right): ?float
    {
        if (! is_array($left) || ! is_array($right) || count($left) !== count($right) || ! $left || count($left) > 64) {
            return null;
        }
        $intersection = $a = $b = 0.0;
        foreach ($left as $index => $value) {
            $other = $right[$index] ?? null;
            if (! is_numeric($value) || ! is_numeric($other) || ! is_finite((float) $value) || ! is_finite((float) $other) || $value < 0 || $other < 0) {
                return null;
            }
            $intersection += min($value, $other);
            $a += $value;
            $b += $other;
        }

        return $a && $b ? min(1, $intersection / max($a, $b)) : null;
    }

    private function local(mixed $left, mixed $right): ?float
    {
        if (! is_array($left) || ! is_array($right) || ! $left || ! $right || count($left) > 32 || count($right) > 32) {
            return null;
        }
        foreach (array_merge($left, $right) as $value) {
            if (! is_string($value) || strlen($value) !== 64 || ! ctype_xdigit($value)) {
                return null;
            }
        }
        $a = array_map('hex2bin', $left);
        $b = array_map('hex2bin', $right);
        static $bits = null;
        $bits ??= array_map(fn ($v) => substr_count(decbin($v), '1'), range(0, 255));
        $directed = function ($from, $to) use ($bits): float {
            $sum = 0;
            foreach ($from as $one) {
                $best = 256;
                foreach ($to as $two) {
                    $distance = 0;
                    foreach (count_chars($one ^ $two, 1) as $byte => $count) {
                        $distance += $bits[$byte] * $count;
                    }
                    $best = min($best, $distance);
                }
                $sum += max(0, 1 - $best / 128);
            }

            return $sum / count($from);
        };

        return ($directed($a, $b) + $directed($b, $a)) / 2;
    }
}
