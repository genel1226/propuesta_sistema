<?php

namespace App\Services\Import;

use App\Models\HeaderAlias;
use App\Models\ImportBatch;
use App\Services\Import\Export\TemplateFiller;
use App\Services\Import\Mapping\HeaderMatcher;
use App\Services\Import\Processing\ImportProcessor;
use App\Services\Import\Processing\ImportResult;
use App\Services\Import\Source\SourceReader;
use App\Services\Import\Source\SourceTable;
use App\Services\Import\Support\Text;
use App\Services\Import\Template\CatalogOption;
use App\Services\Import\Template\TemplateColumn;
use App\Services\Import\Template\TemplateParser;
use App\Services\Import\Template\TemplateSchema;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Orquesta el flujo completo: analizar archivos -> proponer mapeo -> procesar -> exportar.
 *
 * @phpstan-import-type SuggestedEntry from HeaderMatcher
 *
 * @phpstan-type StoredMappingEntry array{mode: string, sources: list<string>, fixed: string, manual: bool}
 * @phpstan-type ImportSettings array{tables: list<string>, extras: list<string>, expand_ranges: bool, code_mode: string}
 * @phpstan-type CompactRow array{excel_row: int, origin: string, status: string, name: string, code: string, values: array<string, string>, issues: list<array{level: string, column: ?string, message: string}>}
 */
class ImportService
{
    public function __construct(
        protected TemplateParser $templateParser,
        protected SourceReader $sourceReader,
        protected HeaderMatcher $headerMatcher,
        protected ImportProcessor $processor,
        protected TemplateFiller $filler,
    ) {}

    /**
     * Analiza la plantilla y el archivo del cliente y guarda el resultado en el lote.
     */
    public function analyze(ImportBatch $batch): void
    {
        $disk = $this->disk();
        $schema = $this->templateParser->parse($disk->path($batch->template_path));
        $tables = $this->sourceReader->read($disk->path($batch->source_path));

        if ($tables === []) {
            throw new \RuntimeException('No se encontró ninguna tabla con encabezados en el archivo del cliente.');
        }

        $batch->update([
            'template_schema' => $schema->toArray(),
            'source_tables' => array_map(fn (SourceTable $t) => $t->summary(), $tables),
            'category' => $schema->category,
        ]);
    }

    /**
     * Encabezados disponibles (clave normalizada => texto) para las tablas elegidas.
     *
     * @param  list<string>  $tableIds
     * @return array<string, string>
     */
    public function sourceHeaders(ImportBatch $batch, array $tableIds): array
    {
        $headers = [];

        foreach ($batch->source_tables ?? [] as $table) {
            if (! in_array($table['id'], $tableIds, true)) {
                continue;
            }

            foreach ($table['headers'] as $label) {
                $key = Text::normalize($label);
                if ($key !== '' && ! isset($headers[$key])) {
                    $headers[$key] = $label;
                }
            }
        }

        return $headers;
    }

    /**
     * Mapeo sugerido + valores por defecto (cliente, localización, estado) + columnas extra.
     *
     * @param  list<string>  $tableIds
     * @return array{mapping: array<string, SuggestedEntry>, extras: list<string>}
     */
    public function suggest(ImportBatch $batch, array $tableIds): array
    {
        $schema = $batch->schema();
        $headers = $this->sourceHeaders($batch, $tableIds);
        $mapping = $this->headerMatcher->suggest($schema, $headers, HeaderAlias::learnedMap());

        $context = $this->contextText($batch, $tableIds);

        foreach ($schema->columns as $column) {
            if ($mapping[$column->key]['mode'] !== 'none' || ! $column->hasCatalog()) {
                continue;
            }

            $guess = match ($column->role) {
                TemplateColumn::ROLE_ESTADO => $this->guessEstado($column),
                TemplateColumn::ROLE_CLIENTE, TemplateColumn::ROLE_LOCALIZACION => $this->guessFromContext($column, $context),
                default => null,
            };

            if ($guess !== null) {
                $mapping[$column->key] = [
                    'mode' => 'fixed',
                    'sources' => [],
                    'fixed' => $guess->value,
                    'score' => 0.7,
                    'reason' => $column->role === TemplateColumn::ROLE_ESTADO ? 'Valor por defecto' : 'Deducido del nombre del archivo / títulos',
                ];
            } elseif (in_array($column->role, [TemplateColumn::ROLE_CLIENTE, TemplateColumn::ROLE_LOCALIZACION], true)) {
                // Sin pista: queda como "valor fijo" para que el usuario elija de la lista.
                $mapping[$column->key] = ['mode' => 'fixed', 'sources' => [], 'fixed' => '', 'score' => 0.0, 'reason' => 'Seleccione un valor'];
            }
        }

        return [
            'mapping' => $mapping,
            'extras' => $this->headerMatcher->suggestExtras($headers, $mapping),
        ];
    }

    /**
     * Procesa el lote (se ejecuta dentro del Job).
     */
    public function process(ImportBatch $batch): void
    {
        $batch->update(['status' => ImportBatch::STATUS_PROCESSING, 'error' => null]);

        try {
            $disk = $this->disk();
            $schema = $batch->schema();
            $settings = $batch->settings ?? [];
            $tableIds = $settings['tables'] ?? [];

            $tables = array_values(array_filter(
                $this->sourceReader->read($disk->path($batch->source_path)),
                fn (SourceTable $t) => in_array($t->id, $tableIds, true)
            ));

            $headers = $this->sourceHeaders($batch, $tableIds);
            $result = $this->processor->process($schema, $tables, $batch->mapping ?? [], $settings, $headers);

            $dir = $batch->directory();
            $base = $this->safeName(pathinfo($batch->template_name, PATHINFO_FILENAME));
            $resultPath = $dir.'/'.$base.' - CON VALIDACION.xlsx';
            $cleanPath = $dir.'/'.$base.' - LISTA PARA IMPORTAR.xlsx';

            $templateFile = $disk->path($batch->template_path);
            $this->filler->write($templateFile, $schema, $result, $disk->path($resultPath), true);
            $this->filler->write($templateFile, $schema, $result, $disk->path($cleanPath), false);

            $this->learn($batch);

            $batch->update([
                'status' => ImportBatch::STATUS_COMPLETED,
                'stats' => $result->stats() + ['notes' => $result->notes],
                'result_rows' => $this->compactRows($schema, $result),
                'result_path' => $resultPath,
                'clean_path' => $cleanPath,
                'cliente' => $this->clienteLabel($schema, $batch->mapping ?? []),
                'processed_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
            $batch->update(['status' => ImportBatch::STATUS_FAILED, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Guarda los mapeos confirmados por el usuario para sugerirlos la próxima vez.
     */
    protected function learn(ImportBatch $batch): void
    {
        foreach ($batch->mapping ?? [] as $templateKey => $entry) {
            // Sólo se aprende lo que el usuario corrigió a mano: las sugerencias automáticas
            // no se refuerzan (evita que un error se propague a otras importaciones).
            if ($entry['mode'] !== 'source' || empty($entry['manual'])) {
                continue;
            }

            foreach ($entry['sources'] as $sourceKey) {
                if ($sourceKey !== $templateKey) {
                    HeaderAlias::learn($sourceKey, $templateKey);
                }
            }
        }
    }

    /**
     * Versión liviana de las filas para mostrar en pantalla.
     *
     * @return list<CompactRow>
     */
    protected function compactRows(TemplateSchema $schema, ImportResult $result): array
    {
        $preview = [];
        $nameColumn = $schema->byRole(TemplateColumn::ROLE_NOMBRE);
        $codeColumn = $schema->byRole(TemplateColumn::ROLE_CODIGO);

        foreach ($result->rows as $row) {
            $values = [];
            foreach ($schema->columns as $column) {
                $value = $row['values'][$column->index] ?? '';
                // En pantalla se muestra la etiqueta, no el ID interno del catálogo.
                if ($column->hasCatalog() && str_contains($value, '|')) {
                    $value = CatalogOption::fromCell($value)->displayLabel();
                }
                $values[$column->key] = $value;
            }

            $preview[] = [
                'excel_row' => $row['excel_row'],
                'origin' => $row['origin'],
                'status' => $result->status($row),
                'name' => $nameColumn ? ($row['values'][$nameColumn->index] ?? '') : '',
                'code' => $codeColumn ? ($row['values'][$codeColumn->index] ?? '') : '',
                'values' => $values,
                'issues' => array_map(fn ($i) => ['level' => $i['level'], 'column' => $i['column'], 'message' => $i['message']], $row['issues']),
            ];
        }

        return $preview;
    }

    /**
     * Texto con nombre de archivo, hojas y títulos: sirve para adivinar cliente/localización.
     *
     * @param  list<string>  $tableIds
     */
    protected function contextText(ImportBatch $batch, array $tableIds): string
    {
        $parts = [pathinfo($batch->source_name, PATHINFO_FILENAME)];

        // Se usa todo el archivo: el título que nombra al cliente puede estar en otra hoja.
        foreach ($batch->source_tables ?? [] as $table) {
            $parts[] = $table['sheet'];
            $parts[] = $table['title'] ?? '';
        }

        return implode(' ', $parts);
    }

    protected function guessFromContext(TemplateColumn $column, string $context): ?CatalogOption
    {
        if (count($column->catalog) === 1) {
            return $column->catalog[0];
        }

        $contextTokens = $this->stems($context);
        $best = null;
        $bestScore = 0.0;
        $tie = false;

        foreach ($column->catalog as $option) {
            $tokens = $this->stems($option->label);
            if ($tokens === []) {
                continue;
            }

            $score = count(array_intersect($tokens, $contextTokens)) / count($tokens);
            if ($score > $bestScore) {
                [$best, $bestScore, $tie] = [$option, $score, false];
            } elseif ($score === $bestScore && $score > 0) {
                $tie = true;
            }
        }

        // Si dos opciones empatan, no se adivina: el usuario elige.
        return $bestScore >= 0.66 && ! $tie ? $best : null;
    }

    protected function guessEstado(TemplateColumn $column): ?CatalogOption
    {
        foreach ($column->catalog as $option) {
            if (Text::normalize($option->label) === 'operativo') {
                return $option;
            }
        }

        return $column->catalog[0] ?? null;
    }

    /**
     * @return list<string>
     */
    protected function stems(string $text): array
    {
        $stop = ['de', 'del', 'la', 'el', 'los', 'las', 'y', 'en', 'carta', 'gantt', 'equipo', 'equipos', 'activo', 'activos'];
        $tokens = [];

        foreach (explode(' ', Text::normalize($text)) as $token) {
            if ($token === '' || ctype_digit($token) || in_array($token, $stop, true)) {
                continue;
            }
            $tokens[] = strlen($token) > 4 ? preg_replace('/(es|s)$/', '', $token) : $token;
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @param  array<string, array{fixed: string}>  $mapping
     */
    protected function clienteLabel(TemplateSchema $schema, array $mapping): ?string
    {
        $column = $schema->byRole(TemplateColumn::ROLE_CLIENTE);
        $fixed = $column ? ($mapping[$column->key]['fixed'] ?? '') : '';

        return $fixed !== '' ? CatalogOption::fromCell($fixed)->label : null;
    }

    protected function safeName(string $name): string
    {
        return trim(preg_replace('/[^\pL\pN \-_().]+/u', '', $name) ?? 'plantilla') ?: 'plantilla';
    }

    protected function disk(): Filesystem
    {
        return Storage::disk(config('importador.disk', 'local'));
    }
}
