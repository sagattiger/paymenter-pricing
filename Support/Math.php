<?php

namespace Paymenter\Extensions\Others\Pricing\Support;

/** Pure price arithmetic for the bulk tools (no database). */
class Math
{
    public const ROUNDING = [
        'cents' => '2 decimals (29.37)',
        '90' => 'Ends in ,90 (29.90)',
        '99' => 'Ends in ,99 (29.99)',
        'whole' => 'Whole number (29)',
        'half' => 'Nearest ,50 (29.50)',
        'five' => 'Nearest 5 (30)',
    ];

    /**
     * New value for one cell.
     *   set      → $value
     *   percent  → old ± $value %   (e.g. 10 = +10 %, -15 = −15 %)
     *   amount   → old ± $value
     *   convert  → $source × $value (e.g. USD = BRL × 0.2)
     *   months   → $source (monthly price) × $months × (1 − $value %)
     * Null when there is nothing to work from.
     */
    public static function apply(string $op, ?float $old, float $value, ?float $source = null, float $months = 1): ?float
    {
        $v = match ($op) {
            'set' => $value,
            'percent' => $old === null ? null : $old * (1 + $value / 100),
            'amount' => $old === null ? null : $old + $value,
            'convert' => $source === null ? null : $source * $value,
            'months' => $source === null ? null : $source * $months * (1 - $value / 100),
            default => null,
        };

        return $v === null ? null : max(0.0, $v);
    }

    public static function round(float $v, string $mode, string $currency = ''): float
    {
        if ($v <= 0) {
            return 0.0;
        }
        $r = match ($mode) {
            '90' => self::ending($v, 0.90),
            '99' => self::ending($v, 0.99),
            'whole' => round($v),
            'half' => round($v * 2) / 2,
            'five' => max(5, round($v / 5) * 5),
            default => round($v, 2),
        };

        return in_array(strtoupper($currency), ['JPY', 'CLP', 'PYG', 'KRW'], true) ? round($r) : round($r, 2);
    }

    /** Nearest price ending in .90 / .99 (31.37 → 30.90 / 30.99; 0.40 → 0.90). */
    private static function ending(float $v, float $end): float
    {
        $n = round($v - $end) + $end;

        return $n < $end ? $end : $n;
    }

    /** "29,90" / "1.299,90" / "29.9" / "" → float|null; false when not a number. */
    public static function parse($input): float|null|false
    {
        $s = trim((string) $input);
        if ($s === '') {
            return null;
        }
        $s = preg_replace('/[^\d,.\-]/', '', $s);
        if (str_contains($s, ',')) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        }

        return is_numeric($s) ? (float) $s : false;
    }

    public static function months(int $period, string $unit): float
    {
        $period = max(1, $period);

        return match ($unit) {
            'year' => 12 * $period,
            'month' => $period,
            'week' => $period / 4.345,
            'day' => $period / 30,
            'hour' => $period / 720,
            default => 1,
        };
    }
}
