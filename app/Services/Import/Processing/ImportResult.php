<?php

namespace App\Services\Import\Processing;

/**
 * Resultado del procesamiento: filas listas para escribir en la plantilla + observaciones por fila.
 *
 * @phpstan-type ImportIssue array{level: string, column: ?string, message: string, code?: string, type?: string}
 * @phpstan-type ImportRow array{excel_row: int, origin: string, values: array<int, string>, issues: list<ImportIssue>}
 * @phpstan-type ImportStats array{total: int, ok: int, warning: int, error: int, errors_count: int, warnings_count: int, generated_codes: int, expanded_rows: int, by_message: array<string, int>}
 */
final class ImportResult
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    public const INFO = 'info';

    /**
     * @param  list<ImportRow>  $rows
     * @param  list<string>  $notes
     */
    public function __construct(
        public array $rows = [],
        public array $notes = [],
    ) {}

    /**
     * @param  ImportRow  $row
     * @return 'ok'|'warning'|'error'
     */
    public function status(array $row): string
    {
        $levels = array_column($row['issues'], 'level');

        return match (true) {
            in_array(self::ERROR, $levels, true) => self::ERROR,
            in_array(self::WARNING, $levels, true) => self::WARNING,
            default => 'ok',
        };
    }

    /**
     * @return ImportStats
     */
    public function stats(): array
    {
        $stats = [
            'total' => count($this->rows),
            'ok' => 0,
            'warning' => 0,
            'error' => 0,
            'errors_count' => 0,
            'warnings_count' => 0,
            'generated_codes' => 0,
            'expanded_rows' => 0,
            'by_message' => [],
        ];

        foreach ($this->rows as $row) {
            $stats[$this->status($row)]++;

            foreach ($row['issues'] as $issue) {
                if ($issue['level'] === self::ERROR) {
                    $stats['errors_count']++;
                } elseif ($issue['level'] === self::WARNING) {
                    $stats['warnings_count']++;
                }

                if (($issue['code'] ?? null) === 'generated_code') {
                    $stats['generated_codes']++;
                }
                if (($issue['code'] ?? null) === 'expanded') {
                    $stats['expanded_rows']++;
                }

                if ($issue['level'] !== self::INFO) {
                    $group = $issue['level'].'|'.($issue['column'] ?? '').'|'.($issue['type'] ?? $issue['message']);
                    $stats['by_message'][$group] = ($stats['by_message'][$group] ?? 0) + 1;
                }
            }
        }

        arsort($stats['by_message']);

        return $stats;
    }

    /**
     * Texto de la columna extra de validación para una fila.
     *
     * @param  ImportRow  $row
     */
    public static function describe(array $row): string
    {
        if ($row['issues'] === []) {
            return 'OK';
        }

        $prefix = [self::ERROR => 'ERROR', self::WARNING => 'ADVERTENCIA', self::INFO => 'INFO'];
        $order = [self::ERROR => 0, self::WARNING => 1, self::INFO => 2];
        $issues = $row['issues'];
        usort($issues, fn ($a, $b) => $order[$a['level']] <=> $order[$b['level']]);

        return implode("\n", array_map(
            fn ($i) => $prefix[$i['level']].': '.($i['column'] ? '['.$i['column'].'] ' : '').$i['message'],
            $issues
        ));
    }
}
