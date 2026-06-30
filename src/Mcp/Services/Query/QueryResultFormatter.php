<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Services\Query;

/**
 * QueryResultFormatter
 *
 * Formats raw numeric values into dashboard-ready display strings.
 *
 * Supported formats:
 *   number   → 1,234,567
 *   currency → ₹12,45,000  (locale-aware, symbol from config)
 *   percent  → 84%
 *   decimal  → 1,234.56
 *   compact  → 1.2M / 45.3K  (abbreviated large numbers)
 *
 * All settings (locale, currency symbol, decimal precision) are driven
 * by config/mcp-dashboard-studio.php — nothing is hardcoded.
 */
class QueryResultFormatter
{
    protected string $locale;

    protected string $currencySymbol;

    protected int $currencyDecimals;

    protected int $numberDecimals;

    protected string $thousandsSeparator;

    protected string $decimalPoint;

    public function __construct()
    {
        $this->locale             = config('mcp-dashboard-studio.formatting.locale', 'en_IN');
        $this->currencySymbol     = config('mcp-dashboard-studio.formatting.currency_symbol', '₹');
        $this->currencyDecimals   = (int) config('mcp-dashboard-studio.formatting.currency_decimals', 2);
        $this->numberDecimals     = (int) config('mcp-dashboard-studio.formatting.number_decimals', 0);
        $this->thousandsSeparator = config('mcp-dashboard-studio.formatting.thousands_separator', ',');
        $this->decimalPoint       = config('mcp-dashboard-studio.formatting.decimal_point', '.');
    }

    // =========================================================================
    // Main Entry Point
    // =========================================================================

    /**
     * Format a value based on a format hint string.
     *
     * @param  mixed   $value   Raw numeric value from DB
     * @param  string  $format  One of: number, currency, percent, decimal, compact
     * @return string           Formatted display string
     */
    public function format(mixed $value, string $format = 'number'): string
    {
        if ($value === null) {
            return '—';
        }

        return match ($format) {
            'currency' => $this->formatCurrency($value),
            'percent'  => $this->formatPercent($value),
            'decimal'  => $this->formatDecimal($value),
            'compact'  => $this->formatCompact($value),
            default    => $this->formatNumber($value),
        };
    }

    /**
     * Format an entire metric array — resolves the 'value' key using the
     * metric's own 'format' hint and adds a 'formatted_value' key.
     *
     * Input:  ['title' => 'Total Orders', 'value' => 1234567, 'format' => 'number']
     * Output: ['title' => 'Total Orders', 'value' => 1234567, 'formatted_value' => '1,234,567']
     *
     * @param  array  $metric
     * @return array
     */
    public function formatMetric(array $metric): array
    {
        $value  = $metric['value']  ?? null;
        $format = $metric['format'] ?? $this->inferFormat($metric);

        $metric['formatted_value'] = $this->format($value, $format);

        return $metric;
    }

    /**
     * Format all metrics in a discovery result set.
     * Applies formatting to KPIs (which have scalar values).
     * Charts, tables, and filters are passed through unchanged.
     *
     * @param  array{kpis: list<array>, charts: list<array>, tables: list<array>, filters: list<array>}  $resolved
     * @return array{kpis: list<array>, charts: list<array>, tables: list<array>, filters: list<array>}
     */
    public function formatAll(array $resolved): array
    {
        return [
            'kpis'    => array_map(fn ($m) => $this->formatMetric($m), $resolved['kpis'] ?? []),
            'charts'  => $resolved['charts']  ?? [],
            'tables'  => $resolved['tables']  ?? [],
            'filters' => $resolved['filters'] ?? [],
        ];
    }

    // =========================================================================
    // Individual Formatters
    // =========================================================================

    /**
     * Format as a plain number with thousands separators.
     *
     * 1234567 → "1,234,567"
     */
    public function formatNumber(mixed $value): string
    {
        return number_format(
            (float) $value,
            $this->numberDecimals,
            $this->decimalPoint,
            $this->thousandsSeparator
        );
    }

    /**
     * Format as currency with symbol prefix.
     *
     * 1245000 → "₹12,45,000.00"
     *
     * Uses Indian numbering (lakhs/crores) when locale is en_IN,
     * standard international grouping otherwise.
     */
    public function formatCurrency(mixed $value): string
    {
        $number = (float) $value;

        if ($this->isIndianLocale()) {
            return $this->currencySymbol . $this->formatIndianNumber($number, $this->currencyDecimals);
        }

        return $this->currencySymbol . number_format(
            $number,
            $this->currencyDecimals,
            $this->decimalPoint,
            $this->thousandsSeparator
        );
    }

    /**
     * Format as a percentage.
     *
     * 84     → "84%"
     * 84.567 → "84.57%"
     * 0.84   → "84%"  (auto-detects 0-1 range and multiplies by 100)
     */
    public function formatPercent(mixed $value): string
    {
        $number = (float) $value;

        // Auto-detect 0-1 range (ratios) and convert to percentage
        if ($number > 0 && $number < 1) {
            $number *= 100;
        }

        // Use 0 decimals for whole numbers, 2 for fractional
        $decimals = (floor($number) == $number) ? 0 : 2;

        return number_format($number, $decimals, $this->decimalPoint, $this->thousandsSeparator) . '%';
    }

    /**
     * Format as a decimal number (always shows decimal places).
     *
     * 1234.5 → "1,234.50"
     */
    public function formatDecimal(mixed $value): string
    {
        return number_format(
            (float) $value,
            $this->currencyDecimals,
            $this->decimalPoint,
            $this->thousandsSeparator
        );
    }

    /**
     * Format as a compact abbreviated number.
     *
     * 1200000 → "1.2M"
     * 45300   → "45.3K"
     * 999     → "999"
     */
    public function formatCompact(mixed $value): string
    {
        $number = (float) $value;
        $abs    = abs($number);
        $sign   = $number < 0 ? '-' : '';

        if ($abs >= 1_000_000_000) {
            return $sign . round($abs / 1_000_000_000, 1) . 'B';
        }

        if ($abs >= 10_000_000 && $this->isIndianLocale()) {
            return $sign . round($abs / 10_000_000, 1) . 'Cr';
        }

        if ($abs >= 100_000 && $this->isIndianLocale()) {
            return $sign . round($abs / 100_000, 1) . 'L';
        }

        if ($abs >= 1_000_000) {
            return $sign . round($abs / 1_000_000, 1) . 'M';
        }

        if ($abs >= 1_000) {
            return $sign . round($abs / 1_000, 1) . 'K';
        }

        return $sign . (string) $number;
    }

    // =========================================================================
    // Indian Number System (Lakhs / Crores)
    // =========================================================================

    /**
     * Format a number using the Indian numbering system.
     *
     * 1245000.50 → "12,45,000.50"
     *
     * Indian grouping: last 3 digits, then groups of 2.
     */
    protected function formatIndianNumber(float $number, int $decimals = 2): string
    {
        $negative = $number < 0;
        $number   = abs($number);

        // Split integer and decimal parts
        $parts   = explode('.', number_format($number, $decimals, '.', ''));
        $integer = $parts[0];
        $decimal = $parts[1] ?? '';

        $length = strlen($integer);

        if ($length <= 3) {
            $formatted = $integer;
        } else {
            // Last 3 digits
            $last3 = substr($integer, -3);
            $rest  = substr($integer, 0, $length - 3);

            // Group remaining digits in pairs from right
            $rest = preg_replace('/(\d)(?=(\d{2})+$)/', '$1,', $rest);

            $formatted = $rest . ',' . $last3;
        }

        $result = $decimals > 0 ? $formatted . '.' . $decimal : $formatted;

        return $negative ? '-' . $result : $result;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Check if the configured locale uses Indian numbering.
     */
    protected function isIndianLocale(): bool
    {
        return str_starts_with($this->locale, 'en_IN')
            || str_starts_with($this->locale, 'hi_IN');
    }

    /**
     * Infer the display format from a metric's unit or column name.
     * Used when no explicit 'format' key is set on the metric.
     */
    protected function inferFormat(array $metric): string
    {
        $unit = $metric['unit'] ?? '';

        return match ($unit) {
            'currency' => 'currency',
            'percent'  => 'percent',
            'decimal'  => 'decimal',
            default    => 'number',
        };
    }
}
