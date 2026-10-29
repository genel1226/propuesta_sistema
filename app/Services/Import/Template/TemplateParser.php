<?php

namespace App\Services\Import\Template;

use App\Services\Import\Support\Text;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * Lee una plantilla de importación del CMMS y descubre sus reglas:
 *  - Columnas y encabezados (la plantilla se personaliza por cliente/categoría,
 *    por eso no hay columnas fijas en el código).
 *  - Campos obligatorios: "(Campo Obligatorio)" en el encabezado.
 *  - Campos únicos: "(Campo único)".
 *  - Opciones escritas en el encabezado: "Ingrese una de las siguientes opciones: ...".
 *  - Catálogos: listas desplegables (validación de datos) que apuntan a la hoja de parámetros.
 *  - Valores fijos: columnas "(No modifique el valor.)" con el valor ya escrito (ej. ID de categoría).
 */
class TemplateParser
{
    public function parse(string $path): TemplateSchema
    {
        $spreadsheet = IOFactory::load($path);

        try {
            return $this->parseSpreadsheet($spreadsheet);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function parseSpreadsheet(Spreadsheet $spreadsheet): TemplateSchema
    {
        $sheet = $this->findDataSheet($spreadsheet);
        [$headerRow, $title] = $this->findHeaderRow($sheet);
        $dataStartRow = $headerRow + 1;

        $validations = $this->collectValidations($spreadsheet, $sheet);
        $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn($headerRow));

        $columns = [];
        $category = null;

        for ($col = 1; $col <= $lastColumn; $col++) {
            $letter = Coordinate::stringFromColumnIndex($col);
            $raw = Text::toString($sheet->getCell($letter.$headerRow)->getValue());

            if ($raw === '') {
                continue;
            }

            $label = Text::firstLine($raw) ?: $raw;
            $key = Text::normalize($label);
            $normalizedRaw = Text::normalize($raw);

            $required = str_contains($normalizedRaw, 'campo obligatorio') || str_contains($normalizedRaw, 'obligatorio');
            $unique = str_contains($normalizedRaw, 'campo unico');

            $fixed = null;
            if (str_contains($normalizedRaw, 'no modifique')) {
                $value = Text::toString($sheet->getCell($letter.$dataStartRow)->getValue());
                $fixed = $value !== '' ? $value : null;
            }

            $role = $this->detectRole($key);

            if ($role === TemplateColumn::ROLE_CATEGORIA && $title !== null && preg_match('/CATEGOR[IÍ]A:\s*(.+)$/iu', $title, $m)) {
                $category = trim($m[1]);
            }

            $columns[] = new TemplateColumn(
                index: $col,
                letter: $letter,
                key: $key,
                label: $label,
                rawHeader: $raw,
                required: $required,
                unique: $unique,
                catalog: $validations[$col] ?? [],
                options: $this->optionsFromHeader($raw),
                fixedValue: $fixed,
                role: $role,
            );
        }

        if ($columns === []) {
            throw new RuntimeException('No se encontraron encabezados en la plantilla.');
        }

        return new TemplateSchema($sheet->getTitle(), $headerRow, $dataStartRow, $title, $category, $columns);
    }

    /**
     * La hoja de datos es la primera hoja visible que no sea de parámetros.
     */
    protected function findDataSheet(Spreadsheet $spreadsheet): Worksheet
    {
        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            $name = Text::normalize($sheet->getTitle());
            if ($sheet->getSheetState() === Worksheet::SHEETSTATE_VISIBLE && ! str_contains($name, 'parametro')) {
                return $sheet;
            }
        }

        return $spreadsheet->getSheet(0);
    }

    /**
     * Encabezado = fila (entre las 10 primeras) con más celdas con texto.
     * Si una fila anterior tiene una sola celda, se toma como título.
     *
     * @return array{0:int,1:?string}
     */
    protected function findHeaderRow(Worksheet $sheet): array
    {
        $bestRow = 1;
        $bestCount = 0;
        $title = null;
        $maxRow = min(10, $sheet->getHighestDataRow());

        for ($row = 1; $row <= $maxRow; $row++) {
            $values = array_filter(
                $sheet->rangeToArray('A'.$row.':'.$sheet->getHighestDataColumn().$row, null, false, false)[0],
                fn ($v) => ! Text::isBlank($v)
            );

            if (count($values) > $bestCount) {
                $bestCount = count($values);
                $bestRow = $row;
            }
        }

        for ($row = 1; $row < $bestRow; $row++) {
            $value = Text::toString($sheet->getCell('A'.$row)->getValue());
            if ($value !== '') {
                $title = preg_replace('/\s+/', ' ', $value);
                break;
            }
        }

        return [$bestRow, $title];
    }

    /**
     * Lee las listas desplegables de la hoja y devuelve los valores permitidos por columna.
     *
     * @return array<int, list<CatalogOption>>
     */
    protected function collectValidations(Spreadsheet $spreadsheet, Worksheet $sheet): array
    {
        $result = [];

        foreach ($sheet->getDataValidationCollection() as $range => $validation) {
            if ($validation->getType() !== DataValidation::TYPE_LIST) {
                continue;
            }

            $values = $this->resolveListFormula($spreadsheet, $sheet, (string) $validation->getFormula1());
            if ($values === []) {
                continue;
            }

            foreach (explode(' ', (string) $range) as $part) {
                [$start] = Coordinate::rangeBoundaries(str_contains($part, ':') ? $part : $part.':'.$part);
                $result[$start[0]] = array_map(fn (string $v) => CatalogOption::fromCell($v), $values);
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    protected function resolveListFormula(Spreadsheet $spreadsheet, Worksheet $sheet, string $formula): array
    {
        $formula = ltrim(trim($formula), '=');

        if ($formula === '') {
            return [];
        }

        // Lista escrita directamente: "Alta,Media,Baja"
        if (str_starts_with($formula, '"')) {
            return array_values(array_filter(array_map('trim', explode(',', trim($formula, '"'))), fn ($v) => $v !== ''));
        }

        // Rango con nombre (ej. grupo_column_b)
        $reference = $formula;
        $named = $spreadsheet->getNamedRange($formula);
        if ($named !== null) {
            $reference = $named->getValue();
        }

        if (! preg_match("/^(?:'?(.+?)'?!)?\\$?([A-Z]+)\\$?(\\d+)(?::\\$?([A-Z]+)\\$?(\\d+))?$/u", $reference, $m)) {
            return [];
        }

        $target = $m[1] !== '' ? $spreadsheet->getSheetByName(str_replace("''", "'", $m[1])) : ($named?->getWorksheet() ?? $sheet);
        if ($target === null) {
            return [];
        }

        $range = $m[2].$m[3].':'.(isset($m[5]) ? $m[4].$m[5] : $m[2].$m[3]);

        $values = [];
        foreach ($target->rangeToArray($range, null, true, false) as $row) {
            foreach ($row as $value) {
                $text = Text::toString($value);
                if ($text !== '') {
                    $values[] = $text;
                }
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * "TIPO DE ACTIVO\nIngrese una de las siguientes opciones:\nMueble\nInmueble" => ['Mueble', 'Inmueble'].
     *
     * @return list<string>
     */
    protected function optionsFromHeader(string $raw): array
    {
        if (! preg_match('/opciones\s*:\s*(.+)$/isu', $raw, $m)) {
            return [];
        }

        $parts = preg_split('/[\n,;\/]+/u', $m[1]) ?: [];

        return array_values(array_filter(array_map(fn ($p) => trim($p, " \t.-"), $parts), fn ($p) => $p !== ''));
    }

    protected function detectRole(string $key): ?string
    {
        return match (true) {
            $key === 'nombre' || str_starts_with($key, 'nombre ') => TemplateColumn::ROLE_NOMBRE,
            str_starts_with($key, 'codigo') => TemplateColumn::ROLE_CODIGO,
            str_starts_with($key, 'sub ubicacion') => TemplateColumn::ROLE_SUB_UBICACION,
            str_starts_with($key, 'cliente') => TemplateColumn::ROLE_CLIENTE,
            str_starts_with($key, 'localizacion') => TemplateColumn::ROLE_LOCALIZACION,
            str_starts_with($key, 'estado') => TemplateColumn::ROLE_ESTADO,
            str_starts_with($key, 'categoria') => TemplateColumn::ROLE_CATEGORIA,
            str_starts_with($key, 'observacion') => TemplateColumn::ROLE_OBSERVACIONES,
            default => null,
        };
    }
}
