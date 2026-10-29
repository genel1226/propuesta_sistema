<?php

namespace App\Services\Import\Source;

use App\Services\Import\Support\Text;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Lee el archivo "desordenado" del cliente y lo convierte en tablas limpias.
 *
 * Problemas que resuelve (vistos en archivos reales de clientes):
 *  - Títulos y leyendas antes del encabezado (el encabezado no está en la fila 1).
 *  - Encabezados en dos filas ("N°" + "Activo", "Consumo" + "en Watt").
 *  - Celdas combinadas verticalmente (Piso = "6°" para 10 filas) => se rellenan.
 *  - Varias tablas en la misma hoja (Generadores y Bombas) y varias hojas.
 *  - Fórmulas (se usa el valor calculado) y fechas.
 */
class SourceReader
{
    /**
     * @return list<SourceTable>
     */
    public function read(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);

        if ($reader instanceof Csv) {
            $reader->setInputEncoding(Csv::GUESS_ENCODING);
            $reader->setDelimiter(null);
        }

        $spreadsheet = $reader->load($path);
        $tables = [];

        try {
            foreach ($spreadsheet->getWorksheetIterator() as $sheetIndex => $sheet) {
                if ($sheet->getSheetState() !== Worksheet::SHEETSTATE_VISIBLE) {
                    continue;
                }

                [$grid, $verticalMerges] = $this->buildGrid($sheet);

                foreach ($this->detectTables($grid, $verticalMerges) as $tableIndex => $table) {
                    $tables[] = new SourceTable(
                        id: $sheetIndex.'-'.$tableIndex,
                        sheet: $sheet->getTitle(),
                        title: $table['title'],
                        headerRows: $table['header_rows'],
                        headers: $table['headers'],
                        rows: $table['rows'],
                    );
                }
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return $tables;
    }

    /**
     * Matriz de textos [fila][columna] (1-based) con las celdas combinadas ya rellenadas.
     *
     * @return array{0: array<int, array<int, string>>, 1: array<int, array<int, int>>}
     */
    protected function buildGrid(Worksheet $sheet): array
    {
        $maxRow = $sheet->getHighestDataRow();
        $maxCol = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $grid = [];

        for ($row = 1; $row <= $maxRow; $row++) {
            for ($col = 1; $col <= $maxCol; $col++) {
                $cell = $sheet->getCell([$col, $row]);
                $grid[$row][$col] = $this->cellText($cell);
            }
        }

        // $verticalMerges[fila][col] = fila donde empieza la combinación vertical que la cubre.
        $verticalMerges = [];

        foreach ($sheet->getMergeCells() as $range) {
            [[$c1, $r1], [$c2, $r2]] = Coordinate::rangeBoundaries($range);
            $value = $grid[$r1][$c1] ?? '';

            for ($r = $r1; $r <= min($r2, $maxRow); $r++) {
                for ($c = $c1; $c <= min($c2, $maxCol); $c++) {
                    if ($r2 > $r1 && $r > $r1) {
                        $verticalMerges[$r][$c] = $r1;
                    }

                    // Las combinaciones horizontales sólo se rellenan en vertical
                    // (un título combinado A1:G1 no debe repetirse en 7 columnas).
                    if ($c === $c1) {
                        $grid[$r][$c] = $value;
                    }
                }
            }
        }

        return [$grid, $verticalMerges];
    }

    protected function cellText(Cell $cell): string
    {
        try {
            $value = $cell->isFormula() ? $cell->getCalculatedValue() : $cell->getValue();
        } catch (Throwable) {
            $value = $cell->getOldCalculatedValue();
        }

        if (is_numeric($value) && ! is_string($value) && Date::isDateTime($cell)) {
            try {
                return Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (Throwable) {
                // se devuelve el número tal cual
            }
        }

        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }

        return preg_replace('/\s+/u', ' ', Text::toString($value)) ?? '';
    }

    /**
     * @param  array<int, array<int, string>>  $grid
     * @param  array<int, array<int, int>>  $verticalMerges
     * @return list<array{title:?string, header_rows:list<int>, headers:array<int,string>, rows:list<array{row:int, values:array<int,string>}>}>
     */
    protected function detectTables(array $grid, array $verticalMerges): array
    {
        $tables = [];
        $current = null;
        $lastTitle = null;
        $rowNumbers = array_keys($grid);
        $count = count($rowNumbers);

        for ($i = 0; $i < $count; $i++) {
            $r = $rowNumbers[$i];
            $values = array_filter($grid[$r], fn ($v) => $v !== '');

            if ($values === []) {
                continue;
            }

            $startsTable = $this->isHeaderCandidate($grid, $r)
                && ($current === null || ($this->previousRowEmpty($grid, $r) && $this->looksLikeNewHeader($values, $current['headers'])));

            if ($startsTable) {
                if ($current !== null) {
                    $tables[] = $current;
                }

                $headerRows = [$r];
                // Encabezado de varias filas.
                while ($i + 1 < $count && $this->isHeaderContinuation($grid, $verticalMerges, $r, $rowNumbers[$i + 1])) {
                    $i++;
                    $headerRows[] = $rowNumbers[$i];
                }

                $current = [
                    'title' => $lastTitle,
                    'header_rows' => $headerRows,
                    'headers' => $this->combineHeaders($grid, $headerRows),
                    'rows' => [],
                ];
                $lastTitle = null;

                continue;
            }

            if ($current === null) {
                // Fila antes del primer encabezado: si tiene un solo texto, es un título.
                $only = reset($values);
                if ($lastTitle === null && count($values) === 1 && ! is_numeric($only)) {
                    $lastTitle = mb_strimwidth($only, 0, 80, '…');
                }

                continue;
            }

            $rowValues = [];
            foreach ($current['headers'] as $col => $_) {
                $rowValues[$col] = $grid[$r][$col] ?? '';
            }

            // Fila vacía dentro de las columnas de la tabla, o repetición del encabezado.
            $nonEmpty = array_filter($rowValues, fn ($v) => $v !== '');
            if ($nonEmpty === [] || $this->isRepeatedHeader($rowValues, $current['headers']) || $this->isTotalsRow($nonEmpty, $current['headers'])) {
                continue;
            }

            $current['rows'][] = ['row' => $r, 'values' => $rowValues];
        }

        if ($current !== null) {
            $tables[] = $current;
        }

        return array_values(array_filter($tables, fn ($t) => $t['rows'] !== []));
    }

    /**
     * @param  array<int, array<int, string>>  $grid
     */
    protected function isHeaderCandidate(array $grid, int $r): bool
    {
        $values = array_values(array_filter($grid[$r], fn ($v) => $v !== ''));

        if (count($values) < 2 || count(array_unique(array_map([Text::class, 'normalize'], $values))) < 2) {
            return false;
        }

        foreach ($values as $value) {
            if (is_numeric(str_replace([',', ' '], ['.', ''], $value)) || mb_strlen($value) > 60) {
                return false;
            }
        }

        $avg = array_sum(array_map('mb_strlen', $values)) / count($values);

        return $avg <= 30 && $this->hasDataBelow($grid, $r);
    }

    /**
     * @param  array<int, array<int, string>>  $grid
     */
    protected function hasDataBelow(array $grid, int $r): bool
    {
        for ($k = $r + 1; $k <= $r + 4; $k++) {
            if (! isset($grid[$k])) {
                return false;
            }

            if (array_filter($grid[$k], fn ($v) => $v !== '') !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<int, string>>  $grid
     */
    protected function previousRowEmpty(array $grid, int $r): bool
    {
        return ! isset($grid[$r - 1]) || array_filter($grid[$r - 1], fn ($v) => $v !== '') === [];
    }

    /**
     * Un encabezado nuevo dentro de la misma hoja debe parecerse a un encabezado:
     * comparte palabras con el encabezado anterior o con el diccionario de sinónimos.
     *
     * @param  array<int, string>  $values
     * @param  array<int, string>  $currentHeaders
     */
    protected function looksLikeNewHeader(array $values, array $currentHeaders): bool
    {
        $known = array_map([Text::class, 'normalize'], $currentHeaders);

        foreach (config('importador.synonyms', []) as $key => $synonyms) {
            $known[] = $key;
            foreach ($synonyms as $s) {
                $known[] = $s;
            }
        }

        $known = array_flip($known);
        $hits = 0;

        foreach ($values as $value) {
            if (isset($known[Text::normalize($value)])) {
                $hits++;
            }
        }

        return $hits >= 2;
    }

    /**
     * @param  array<int, array<int, string>>  $grid
     * @param  array<int, array<int, int>>  $verticalMerges
     */
    protected function isHeaderContinuation(array $grid, array $verticalMerges, int $headerRow, int $candidate): bool
    {
        if ($candidate !== $headerRow + 1 || ! isset($grid[$candidate])) {
            return false;
        }

        $values = array_filter($grid[$candidate], fn ($v) => $v !== '');
        if ($values === []) {
            return false;
        }

        foreach ($values as $value) {
            if (is_numeric(str_replace([',', ' '], ['.', ''], $value))) {
                return false;
            }
        }

        // Caso típico: el encabezado está combinado verticalmente con la fila siguiente.
        $mergedFromHeader = array_filter($verticalMerges[$candidate] ?? [], fn ($start) => $start === $headerRow);
        if ($mergedFromHeader !== []) {
            return true;
        }

        // Sin combinaciones: fila corta de textos breves justo debajo del encabezado (ej. unidades).
        $headerCount = count(array_filter($grid[$headerRow], fn ($v) => $v !== ''));

        return count($values) <= max(1, intdiv($headerCount, 2))
            && max(array_map('mb_strlen', $values)) <= 20
            && $this->hasDataBelow($grid, $candidate);
    }

    /**
     * @param  array<int, array<int, string>>  $grid
     * @param  non-empty-list<int>  $headerRows
     * @return array<int, string>
     */
    protected function combineHeaders(array $grid, array $headerRows): array
    {
        $maxCol = max(array_map(fn ($r) => max(0, ...array_keys($grid[$r])), $headerRows));
        $headers = [];
        $lastNonEmpty = 0;

        for ($col = 1; $col <= $maxCol; $col++) {
            $parts = [];
            foreach ($headerRows as $r) {
                $value = trim($grid[$r][$col] ?? '');
                if ($value !== '' && ! in_array(Text::normalize($value), array_map([Text::class, 'normalize'], $parts), true)) {
                    $parts[] = $value;
                }
            }

            $headers[$col] = trim(implode(' ', $parts));
            if ($headers[$col] !== '') {
                $lastNonEmpty = $col;
            }
        }

        // Quita columnas vacías al final y nombra las intermedias sin encabezado.
        $headers = array_filter($headers, fn ($col) => $col <= $lastNonEmpty, ARRAY_FILTER_USE_KEY);
        foreach ($headers as $col => $label) {
            if ($label === '') {
                $headers[$col] = 'Columna '.Coordinate::stringFromColumnIndex($col);
            }
        }

        // Encabezados repetidos => se numeran para poder distinguirlos.
        $seen = [];
        foreach ($headers as $col => $label) {
            $norm = Text::normalize($label);
            if (isset($seen[$norm])) {
                $headers[$col] = $label.' ('.Coordinate::stringFromColumnIndex($col).')';
            }
            $seen[$norm] = true;
        }

        return $headers;
    }

    /**
     * Filas de totales o cálculos al pie de la tabla: sólo números y muy pocas celdas,
     * o una etiqueta tipo "Total" seguida de números.
     *
     * @param  array<int, string>  $nonEmpty
     * @param  array<int, string>  $headers
     */
    protected function isTotalsRow(array $nonEmpty, array $headers): bool
    {
        $texts = array_filter($nonEmpty, fn ($v) => ! is_numeric($v));

        if ($texts === []) {
            return count($nonEmpty) <= max(2, (int) floor(count($headers) * 0.3));
        }

        if (count($texts) === 1 && preg_match('/^(sub\s*)?total(es)?\b/i', Text::normalize(reset($texts)))) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<int, string>  $rowValues
     * @param  array<int, string>  $headers
     */
    protected function isRepeatedHeader(array $rowValues, array $headers): bool
    {
        $same = 0;
        foreach ($rowValues as $col => $value) {
            if ($value !== '' && Text::normalize($value) === Text::normalize($headers[$col] ?? '')) {
                $same++;
            }
        }

        return $same >= 2;
    }
}
