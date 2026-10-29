<?php

namespace App\Services\Import\Processing;

use App\Services\Import\Mapping\CatalogMatcher;
use App\Services\Import\Source\SourceTable;
use App\Services\Import\Support\Text;
use App\Services\Import\Template\CatalogOption;
use App\Services\Import\Template\TemplateColumn;
use App\Services\Import\Template\TemplateSchema;

/**
 * Llena la plantilla (en memoria) con los datos del cliente y valida fila por fila.
 *
 * Validaciones:
 *  - Campos obligatorios vacíos.
 *  - Valores duplicados en columnas únicas (código de activo) dentro del archivo.
 *  - Valores que no existen en los catálogos de la plantilla (localización, cliente,
 *    sub ubicación, estado...) o en las opciones del encabezado.
 *  - Sub ubicación que pertenece a otro cliente.
 *  - Formato del código y largo de los textos.
 *
 * @phpstan-import-type ImportIssue from ImportResult
 */
class ImportProcessor
{
    public function __construct(
        protected CatalogMatcher $catalogMatcher = new CatalogMatcher,
        protected RangeExpander $rangeExpander = new RangeExpander,
    ) {}

    /**
     * @param  list<SourceTable>  $tables  Tablas seleccionadas por el usuario.
     * @param  array<string, array{mode:string, sources?:list<string>, fixed?:string}>  $mapping  clave plantilla => configuración.
     * @param  array{extras?:list<string>, expand_ranges?:bool, code_mode?:string}  $settings
     * @param  array<string, string>  $sourceLabels  clave normalizada => encabezado visible (para observaciones).
     */
    public function process(TemplateSchema $schema, array $tables, array $mapping, array $settings = [], array $sourceLabels = []): ImportResult
    {
        $result = new ImportResult;
        $codeColumn = $schema->byRole(TemplateColumn::ROLE_CODIGO);
        $nameColumn = $schema->byRole(TemplateColumn::ROLE_NOMBRE);
        $obsColumn = $schema->byRole(TemplateColumn::ROLE_OBSERVACIONES);
        $extras = $settings['extras'] ?? [];
        $expandRanges = (bool) ($settings['expand_ranges'] ?? true);
        $codeMode = $settings['code_mode'] ?? 'fill'; // fill | generate_all | keep

        if ($extras !== [] && $obsColumn === null) {
            $result->notes[] = 'La plantilla no tiene columna de OBSERVACIONES: las columnas extra seleccionadas no se incluyeron.';
            $extras = [];
        }

        // 1) Construir filas "crudas" (texto del cliente por columna de plantilla).
        $rawRows = [];
        foreach ($tables as $table) {
            $headerIndex = [];
            foreach ($table->headers as $col => $label) {
                $headerIndex[Text::normalize($label)] = $col;
            }

            foreach ($table->rows as $row) {
                $raw = [];
                foreach ($schema->columns as $column) {
                    $raw[$column->index] = $this->rawValue($column, $mapping[$column->key] ?? ['mode' => 'none'], $row['values'], $headerIndex);
                }

                if ($obsColumn !== null && $extras !== []) {
                    $parts = $raw[$obsColumn->index] !== '' ? [$raw[$obsColumn->index]] : [];
                    foreach ($extras as $extraKey) {
                        $col = $headerIndex[$extraKey] ?? null;
                        $value = $col !== null ? trim($row['values'][$col] ?? '') : '';
                        if ($value !== '') {
                            $parts[] = ($sourceLabels[$extraKey] ?? $table->headers[$col]).': '.$value;
                        }
                    }
                    $raw[$obsColumn->index] = implode(' | ', $parts);
                }

                // Si una fila del cliente no aporta ningún dato mapeado, se ignora.
                $hasData = false;
                foreach ($schema->columns as $column) {
                    $mode = $mapping[$column->key]['mode'] ?? 'none';
                    if ($mode === 'source' && $raw[$column->index] !== '') {
                        $hasData = true;
                        break;
                    }
                }
                if (! $hasData) {
                    continue;
                }

                $origin = $table->sheet.' · fila '.$row['row'];

                // 2) Expandir rangos "SP6NM01 AL 28".
                $rangeColumn = ($codeColumn && ($mapping[$codeColumn->key]['mode'] ?? '') === 'source') ? $codeColumn : $nameColumn;
                $expanded = null;
                if ($expandRanges && $rangeColumn !== null && $raw[$rangeColumn->index] !== '') {
                    $expanded = $this->rangeExpander->expand($raw[$rangeColumn->index]);
                }

                if ($expanded !== null) {
                    $original = $raw[$rangeColumn->index];
                    foreach ($expanded as $i => $code) {
                        $copy = $raw;
                        foreach ($copy as $idx => $value) {
                            if ($value === $original) {
                                $copy[$idx] = $code;
                            }
                        }
                        $rawRows[] = [
                            'origin' => $origin,
                            'raw' => $copy,
                            'issues' => [[
                                'level' => ImportResult::INFO,
                                'column' => $rangeColumn->label,
                                'message' => 'Generado desde el rango «'.$original.'» ('.($i + 1).' de '.count($expanded).').',
                                'code' => 'expanded',
                            ]],
                        ];
                    }
                } else {
                    $rawRows[] = ['origin' => $origin, 'raw' => $raw, 'issues' => []];
                }
            }
        }

        // 3) Resolver catálogos, códigos y validar.
        $existingCodes = [];
        if ($codeColumn !== null && $codeMode !== 'generate_all') {
            foreach ($rawRows as $r) {
                if ($r['raw'][$codeColumn->index] !== '') {
                    $existingCodes[] = $r['raw'][$codeColumn->index];
                }
            }
        }
        $codeGenerator = new CodeGenerator($existingCodes);

        $clienteColumn = $schema->byRole(TemplateColumn::ROLE_CLIENTE);
        $excelRow = $schema->dataStartRow;

        foreach ($rawRows as $r) {
            $values = [];
            $issues = $r['issues'];

            // Cliente primero: la sub ubicación depende de él.
            $clienteLabel = null;
            $ordered = $schema->columns;
            usort($ordered, fn ($a, $b) => ($b->role === TemplateColumn::ROLE_CLIENTE) <=> ($a->role === TemplateColumn::ROLE_CLIENTE));

            foreach ($ordered as $column) {
                $value = $r['raw'][$column->index];

                if ($column->isFixed()) {
                    $values[$column->index] = $column->fixedValue;

                    continue;
                }

                if ($column->hasCatalog() && $value !== '') {
                    $value = $this->resolveCatalog($column, $value, $column->role === TemplateColumn::ROLE_SUB_UBICACION ? $clienteLabel : null, $issues);
                    if ($column->role === TemplateColumn::ROLE_CLIENTE && $value !== '') {
                        $clienteLabel = CatalogOption::fromCell($value)->label;
                    }
                } elseif ($column->hasOptions() && $value !== '') {
                    $value = $this->resolveOptions($column, $value, $issues);
                }

                $values[$column->index] = $value;
            }

            if ($clienteColumn === null) {
                $clienteLabel = null;
            }

            // Código de activo.
            if ($codeColumn !== null) {
                $current = $values[$codeColumn->index];
                if ($codeMode === 'generate_all' || ($codeMode === 'fill' && $current === '')) {
                    $base = $nameColumn ? $values[$nameColumn->index] : '';
                    if ($base !== '') {
                        $values[$codeColumn->index] = $codeGenerator->generate($base);
                        $issues[] = [
                            'level' => ImportResult::INFO,
                            'column' => $codeColumn->label,
                            'message' => 'Código generado automáticamente: '.$values[$codeColumn->index].'.',
                            'code' => 'generated_code',
                        ];
                    }
                } elseif ($current !== '' && ! preg_match('/^[\pL\pN][\pL\pN.\-_\/ ]*$/u', $current)) {
                    $issues[] = ['level' => ImportResult::WARNING, 'column' => $codeColumn->label, 'message' => 'El código «'.$current.'» tiene caracteres poco comunes.', 'type' => 'Código con caracteres poco comunes'];
                }
            }

            // Obligatorios y largo de texto.
            foreach ($schema->columns as $column) {
                $value = $values[$column->index] ?? '';

                if ($column->required && $value === '') {
                    $issues[] = ['level' => ImportResult::ERROR, 'column' => $column->label, 'message' => 'Campo obligatorio vacío.', 'type' => 'Campo obligatorio vacío'];
                }

                if (mb_strlen($value) > 255 && ! $column->hasCatalog()) {
                    $issues[] = ['level' => ImportResult::WARNING, 'column' => $column->label, 'message' => 'Texto de '.mb_strlen($value).' caracteres (máx. recomendado 255).', 'type' => 'Texto muy largo'];
                }
            }

            ksort($values);
            $result->rows[] = [
                'excel_row' => $excelRow++,
                'origin' => $r['origin'],
                'values' => $values,
                'issues' => $issues,
            ];
        }

        $this->validateUnique($schema, $result);

        return $result;
    }

    /**
     * @param  array{mode: string, sources?: list<string>, fixed?: string}  $config
     * @param  array<int, string>  $rowValues
     * @param  array<string, int>  $headerIndex
     */
    protected function rawValue(TemplateColumn $column, array $config, array $rowValues, array $headerIndex): string
    {
        $mode = $config['mode'];

        if ($mode === 'fixed') {
            return trim((string) ($config['fixed'] ?? ''));
        }

        if ($mode !== 'source') {
            return '';
        }

        $parts = [];
        foreach ($config['sources'] ?? [] as $sourceKey) {
            $col = $headerIndex[$sourceKey] ?? null;
            $value = $col !== null ? trim($rowValues[$col] ?? '') : '';
            if ($value !== '' && ! in_array($value, $parts, true)) {
                $parts[] = $value;
            }
        }

        return implode(' - ', $parts);
    }

    /**
     * @param  list<ImportIssue>  $issues
     */
    protected function resolveCatalog(TemplateColumn $column, string $value, ?string $cliente, array &$issues): string
    {
        $match = $this->catalogMatcher->match($value, $column->catalog, $cliente);

        if ($match['option'] !== null) {
            if (! $match['exact'] && $match['score'] < (float) config('importador.catalog_confident', 0.93)) {
                $issues[] = [
                    'level' => ImportResult::WARNING,
                    'column' => $column->label,
                    'message' => '«'.$value.'» se interpretó como «'.$match['option']->displayLabel().'» (similitud '.round($match['score'] * 100).'%). Verifique.',
                    'type' => 'Valor de catálogo interpretado',
                ];
            }

            return $match['option']->value;
        }

        if ($cliente !== null) {
            $other = $this->catalogMatcher->matchAnyCliente($value, $column->catalog);
            if ($other !== null && $other->cliente !== null) {
                $issues[] = [
                    'level' => ImportResult::ERROR,
                    'column' => $column->label,
                    'message' => '«'.$value.'» pertenece al cliente «'.$other->cliente.'», no a «'.$cliente.'».',
                    'type' => 'Sub ubicación de otro cliente',
                ];

                return $value;
            }
        }

        $issues[] = [
            'level' => ImportResult::ERROR,
            'column' => $column->label,
            'message' => '«'.$value.'» no existe en el catálogo de la plantilla.',
            'type' => 'Valor fuera de catálogo',
        ];

        return $value;
    }

    /**
     * @param  list<ImportIssue>  $issues
     */
    protected function resolveOptions(TemplateColumn $column, string $value, array &$issues): string
    {
        $normalized = Text::normalize($value);
        $best = null;
        $bestScore = 0.0;

        foreach ($column->options as $option) {
            if (Text::normalize($option) === $normalized) {
                return $option;
            }
            $score = Text::similarity($value, $option);
            if ($score > $bestScore) {
                [$best, $bestScore] = [$option, $score];
            }
        }

        if ($best !== null && $bestScore >= 0.8) {
            $issues[] = ['level' => ImportResult::WARNING, 'column' => $column->label, 'message' => '«'.$value.'» se interpretó como «'.$best.'».', 'type' => 'Valor de opción interpretado'];

            return $best;
        }

        $issues[] = [
            'level' => ImportResult::ERROR,
            'column' => $column->label,
            'message' => '«'.$value.'» no es una opción válida ('.implode(', ', $column->options).').',
            'type' => 'Opción no válida',
        ];

        return $value;
    }

    protected function validateUnique(TemplateSchema $schema, ImportResult $result): void
    {
        foreach ($schema->columns as $column) {
            if (! $column->unique) {
                continue;
            }

            $seen = [];
            foreach ($result->rows as $i => $row) {
                $value = mb_strtoupper(trim($row['values'][$column->index] ?? ''));
                if ($value !== '') {
                    $seen[$value][] = $i;
                }
            }

            foreach ($seen as $value => $indexes) {
                if (count($indexes) < 2) {
                    continue;
                }

                foreach ($indexes as $i) {
                    $others = array_map(fn ($j) => $result->rows[$j]['excel_row'], array_filter($indexes, fn ($j) => $j !== $i));
                    $row = $result->rows[$i];
                    $row['issues'][] = [
                        'level' => ImportResult::ERROR,
                        'column' => $column->label,
                        'message' => 'Valor duplicado «'.$row['values'][$column->index].'» (también en fila '.implode(', ', $others).').',
                        'type' => 'Valor duplicado',
                    ];
                    $result->rows[$i] = $row;
                }
            }
        }
    }
}
