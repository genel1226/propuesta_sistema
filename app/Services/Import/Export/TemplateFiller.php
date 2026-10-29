<?php

namespace App\Services\Import\Export;

use App\Services\Import\Processing\ImportResult;
use App\Services\Import\Template\TemplateSchema;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Escribe el resultado sobre una copia de la plantilla original del CMMS, conservando
 * su hoja de parámetros, listas desplegables y formato.
 *
 *  - Versión "con validación": todas las filas + columna extra con errores/advertencias + hoja resumen.
 *  - Versión "lista para importar": sólo filas sin errores y sin columnas extra.
 *
 * @phpstan-import-type ImportRow from ImportResult
 */
class TemplateFiller
{
    public const VALIDATION_HEADER = "RESULTADO VALIDACIÓN\n(Eliminar antes de importar)";

    public function write(string $templatePath, TemplateSchema $schema, ImportResult $result, string $outputPath, bool $withValidation = true): void
    {
        $spreadsheet = IOFactory::load($templatePath);
        $sheet = $spreadsheet->getSheetByName($schema->sheetName) ?? $spreadsheet->getSheet(0);

        $rows = $withValidation
            ? $result->rows
            : array_values(array_filter($result->rows, fn ($r) => $result->status($r) !== ImportResult::ERROR));

        $this->clearDataArea($sheet, $schema);

        $rowNumber = $schema->dataStartRow;
        foreach ($rows as $row) {
            foreach ($schema->columns as $column) {
                $value = $row['values'][$column->index] ?? '';
                if ($value !== '') {
                    // Siempre como texto: evita que "01" o "1.1" se conviertan en número.
                    $sheet->setCellValueExplicit([$column->index, $rowNumber], $value, DataType::TYPE_STRING);
                }
            }
            $rowNumber++;
        }

        $lastDataRow = max($schema->dataStartRow, $rowNumber - 1);
        $this->extendValidations($sheet, $schema, $lastDataRow);

        if ($withValidation) {
            $this->writeValidationColumn($sheet, $schema, $result, $rows);
            $this->writeSummarySheet($spreadsheet, $result);
        }

        $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($sheet));

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($outputPath);
        $spreadsheet->disconnectWorksheets();
    }

    /**
     * Borra valores de ejemplo bajo el encabezado (los valores fijos se reescriben en cada fila).
     */
    protected function clearDataArea(Worksheet $sheet, TemplateSchema $schema): void
    {
        $highest = $sheet->getHighestDataRow();

        for ($r = $schema->dataStartRow; $r <= $highest; $r++) {
            foreach ($schema->columns as $column) {
                $sheet->getCell([$column->index, $r])->setValue(null);
            }
        }
    }

    /**
     * Las listas desplegables de la plantilla suelen cubrir hasta la fila 502;
     * si hay más filas se extienden para que el archivo siga siendo válido.
     */
    protected function extendValidations(Worksheet $sheet, TemplateSchema $schema, int $lastDataRow): void
    {
        foreach ($sheet->getDataValidationCollection() as $range => $validation) {
            if (! preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', (string) $range, $m)) {
                continue;
            }

            if ((int) $m[4] >= $lastDataRow) {
                continue;
            }

            $newRange = $m[1].$m[2].':'.$m[3].$lastDataRow;
            $clone = clone $validation;
            $clone->setSqref($newRange);
            $sheet->setDataValidation((string) $range, null);
            $sheet->setDataValidation($newRange, $clone);
        }
    }

    /**
     * @param  list<ImportRow>  $rows
     */
    protected function writeValidationColumn(Worksheet $sheet, TemplateSchema $schema, ImportResult $result, array $rows): void
    {
        $col = $schema->lastColumnIndex() + 1;
        $letter = Coordinate::stringFromColumnIndex($col);
        $headerCell = $letter.$schema->headerRow;

        $sourceHeader = Coordinate::stringFromColumnIndex($schema->lastColumnIndex()).$schema->headerRow;
        $sheet->duplicateStyle($sheet->getStyle($sourceHeader), $headerCell);
        $sheet->setCellValue($headerCell, self::VALIDATION_HEADER);
        $sheet->getStyle($headerCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1F2937');
        $sheet->getStyle($headerCell)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getColumnDimension($letter)->setWidth(70);

        $colors = [ImportResult::ERROR => 'FDE2E2', ImportResult::WARNING => 'FEF3C7', 'ok' => 'DCFCE7'];

        $r = $schema->dataStartRow;
        foreach ($rows as $row) {
            $status = $result->status($row);
            $text = ImportResult::describe($row);
            $cell = $letter.$r;

            $sheet->setCellValueExplicit($cell, $text, DataType::TYPE_STRING);
            $style = $sheet->getStyle($cell);
            $style->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($colors[$status]);

            if ($status === ImportResult::ERROR) {
                $style->getFont()->getColor()->setRGB('991B1B');
            }

            $r++;
        }
    }

    protected function writeSummarySheet(Spreadsheet $spreadsheet, ImportResult $result): void
    {
        $stats = $result->stats();
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('RESUMEN VALIDACIÓN');

        $lines = [
            ['Resumen de la importación', ''],
            ['Filas procesadas', $stats['total']],
            ['Filas sin observaciones', $stats['ok']],
            ['Filas con advertencias', $stats['warning']],
            ['Filas con errores', $stats['error']],
            ['Códigos generados automáticamente', $stats['generated_codes']],
            ['Filas creadas desde rangos', $stats['expanded_rows']],
            ['', ''],
            ['Tipo de observación', 'Cantidad'],
        ];

        foreach ($stats['by_message'] as $group => $count) {
            [$level, $column, $type] = explode('|', $group, 3);
            $lines[] = [($level === ImportResult::ERROR ? 'ERROR' : 'ADVERTENCIA').' · '.($column ? $column.' · ' : '').$type, $count];
        }

        foreach ($result->notes as $note) {
            $lines[] = ['Nota: '.$note, ''];
        }

        $sheet->fromArray($lines, null, 'A1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A9:B9')->getFont()->setBold(true);
        $sheet->getColumnDimension('A')->setWidth(70);
        $sheet->getColumnDimension('B')->setWidth(14);
    }
}
