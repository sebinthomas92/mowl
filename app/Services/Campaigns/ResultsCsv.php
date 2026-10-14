<?php

namespace App\Services\Campaigns;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Reads a daily results export from any analytics tool or store: one row per day, with visits,
 * orders and revenue in whichever columns the tool names them. Totals rows and comment lines
 * (GA4 starts exports with "#" lines) are skipped.
 */
class ResultsCsv
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    public const MAX_DAYS = 1000;

    /** Column names each metric is read from, most specific first. */
    private const COLUMNS = [
        'date' => ['date', 'day', 'order date', 'date created', 'created at', 'reporting date', 'report date'],
        'visits' => ['visits', 'sessions', 'page visits', 'page views', 'pageviews', 'views', 'unique visitors', 'visitors', 'users', 'total users', 'active users', 'landing page visits', 'clicks', 'link clicks'],
        'orders' => ['orders', 'purchases', 'transactions', 'conversions', 'key events', 'ecommerce purchases', 'orders placed', 'sales count', 'results'],
        'revenue' => ['revenue', 'purchase revenue', 'total revenue', 'net sales', 'gross sales', 'total sales', 'sales', 'conversion value', 'purchase conversion value', 'value'],
    ];

    private const SYMBOLS = ['$' => 'USD', '€' => 'EUR', '£' => 'GBP', '₹' => 'INR', '¥' => 'JPY'];

    /**
     * @return array{daily: array<int, array{date: string, visits: ?int, orders: ?int, revenue: ?float}>, visits: ?int, orders: ?int, revenue: ?float, currency: ?string, start: string, end: string}
     */
    public function parse(string $csv): array
    {
        if (strlen($csv) > self::MAX_BYTES) {
            throw $this->invalid('The file is larger than 2 MB. Export one row per day instead.');
        }
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', $csv), fn (string $line) => trim($line) !== '' && ! str_starts_with(ltrim($line), '#')));
        if (! $lines) {
            throw $this->invalid('The file is empty.');
        }
        $delimiter = $this->delimiter($lines[0]);
        $rawHeader = str_getcsv($lines[0], $delimiter, '"', '');
        $header = array_map(fn ($cell) => $this->normalize((string) $cell), $rawHeader);
        $columns = $this->columns($header);
        // "Net sales (EUR)" names the currency in the heading rather than in each cell.
        $headerCurrency = isset($columns['revenue']) ? $this->currency((string) $rawHeader[$columns['revenue']]) : null;
        if (! isset($columns['date'])) {
            throw $this->invalid('The first row needs a column called Date (or Day). Export one row per day.');
        }
        if (! array_intersect_key($columns, array_flip(['visits', 'orders', 'revenue']))) {
            throw $this->invalid('No visits, orders or revenue column was found. Name them Visits (or Sessions), Orders (or Conversions) and Revenue.');
        }

        $rows = [];
        foreach (array_slice($lines, 1) as $line) {
            $cells = str_getcsv($line, $delimiter, '"', '');
            $date = trim((string) ($cells[$columns['date']] ?? ''));
            if ($date === '' || preg_match('/^(grand\s+)?totals?\b/i', $date)) {
                continue;
            }
            $rows[] = ['date' => $date] + array_map(fn (int $index) => trim((string) ($cells[$index] ?? '')), array_diff_key($columns, ['date' => 0]));
        }
        if (! $rows) {
            throw $this->invalid('The file has a header but no daily rows.');
        }

        $dayFirst = $this->dayFirst(array_column($rows, 'date'));
        $daily = [];
        $currency = null;
        foreach ($rows as $number => $row) {
            $date = $this->date($row['date'], $dayFirst);
            if (! $date) {
                throw $this->invalid('Row '.($number + 2)." has a date we can't read (\"".mb_strimwidth($row['date'], 0, 30, '…').'"). Use YYYY-MM-DD.');
            }
            $day = $daily[$date] ?? ['date' => $date, 'visits' => null, 'orders' => null, 'revenue' => null];
            foreach (['visits', 'orders', 'revenue'] as $metric) {
                if (! array_key_exists($metric, $row) || $row[$metric] === '') {
                    continue;
                }
                $value = $this->number($row[$metric]);
                if ($value === null || $value < 0) {
                    throw $this->invalid('Row '.($number + 2).' has a '.$metric.' value we can\'t read ("'.mb_strimwidth($row[$metric], 0, 30, '…').'").');
                }
                $currency ??= $metric === 'revenue' ? $this->currency($row[$metric]) : null;
                $day[$metric] = ($day[$metric] ?? 0) + ($metric === 'revenue' ? $value : (int) round($value));
            }
            $daily[$date] = $day;
        }
        $currency ??= $headerCurrency;
        if (count($daily) > self::MAX_DAYS) {
            throw $this->invalid('The file has more than '.self::MAX_DAYS.' days. Export the campaign window only.');
        }
        ksort($daily);
        $daily = array_map(fn (array $day) => ['revenue' => $day['revenue'] === null ? null : round($day['revenue'], 2)] + $day, array_values($daily));

        return self::summarize($daily) + ['currency' => $currency];
    }

    /**
     * Totals and window of daily rows; null for a metric no row reports.
     *
     * @return array{daily: array, visits: ?int, orders: ?int, revenue: ?float, start: string, end: string}
     */
    public static function summarize(array $daily): array
    {
        $total = function (string $metric) use ($daily) {
            $values = array_filter(array_column($daily, $metric), fn ($value) => $value !== null);

            return $values ? array_sum($values) : null;
        };
        $revenue = $total('revenue');

        return [
            'daily' => array_values($daily),
            'visits' => $total('visits'),
            'orders' => $total('orders'),
            'revenue' => $revenue === null ? null : round($revenue, 2),
            'start' => $daily[0]['date'],
            'end' => $daily[count($daily) - 1]['date'],
        ];
    }

    private function delimiter(string $header): string
    {
        $best = ',';
        foreach ([';', "\t"] as $candidate) {
            if (substr_count($header, $candidate) > substr_count($header, $best)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private function normalize(string $cell): string
    {
        $cell = mb_strtolower(trim($cell, " \t\"'"));
        $cell = preg_replace('/\s*\(.*?\)\s*/', ' ', $cell); // "Revenue (USD)" → "revenue"
        $cell = preg_replace('/[_\-]+/', ' ', $cell);

        return trim(preg_replace('/\s+/', ' ', $cell));
    }

    /** @return array<string, int> metric => column index */
    private function columns(array $header): array
    {
        $found = [];
        foreach (self::COLUMNS as $metric => $names) {
            foreach ($names as $name) {
                $index = array_search($name, $header, true);
                if ($index !== false && ! in_array($index, $found, true)) {
                    $found[$metric] = $index;
                    break;
                }
            }
        }

        return $found;
    }

    /** Slashed dates are day-first unless a value proves otherwise (a first part above 12 can only be a day). */
    private function dayFirst(array $dates): bool
    {
        $monthFirst = false;
        foreach ($dates as $date) {
            if (preg_match('#^(\d{1,2})[/.](\d{1,2})[/.]\d{2,4}$#', trim($date), $match)) {
                if ((int) $match[1] > 12) {
                    return true;
                }
                if ((int) $match[2] > 12) {
                    $monthFirst = true;
                }
            }
        }

        return ! $monthFirst;
    }

    private function date(string $value, bool $dayFirst): ?string
    {
        $value = trim($value);
        try {
            if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[T\s].*)?$/', $value, $m)) {
                return $this->ymd((int) $m[1], (int) $m[2], (int) $m[3]);
            }
            if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m)) {
                return $this->ymd((int) $m[1], (int) $m[2], (int) $m[3]);
            }
            if (preg_match('#^(\d{1,2})[/.](\d{1,2})[/.](\d{2,4})$#', $value, $m)) {
                $year = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];

                return $dayFirst ? $this->ymd($year, (int) $m[2], (int) $m[1]) : $this->ymd($year, (int) $m[1], (int) $m[2]);
            }
            if (preg_match('/[a-z]/i', $value) && preg_match('/\d{4}/', $value)) {
                return CarbonImmutable::parse($value)->format('Y-m-d');
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function ymd(int $year, int $month, int $day): ?string
    {
        return checkdate($month, $day, $year) && $year >= 2000 && $year <= 2100 ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    private function number(string $value): ?float
    {
        $clean = preg_replace('/[^\d.,\-]/u', '', $value);
        if ($clean === '' || $clean === '-') {
            return null;
        }
        if (str_contains($clean, ',') && str_contains($clean, '.')) {
            // Whichever separator comes last is the decimal point: 1,234.50 or 1.234,50.
            $clean = strrpos($clean, ',') > strrpos($clean, '.') ? str_replace(',', '.', str_replace('.', '', $clean)) : str_replace(',', '', $clean);
        } elseif (preg_match('/^-?\d+,\d{1,2}$/', $clean)) {
            $clean = str_replace(',', '.', $clean);
        } else {
            $clean = str_replace(',', '', $clean);
        }

        return is_numeric($clean) ? (float) $clean : null;
    }

    private function currency(string $value): ?string
    {
        foreach (self::SYMBOLS as $symbol => $code) {
            if (str_contains($value, $symbol)) {
                return $code;
            }
        }

        return preg_match('/\b([A-Z]{3})\b/', $value, $match) ? $match[1] : null;
    }

    private function invalid(string $message): ValidationException
    {
        return ValidationException::withMessages(['csv' => $message]);
    }
}
