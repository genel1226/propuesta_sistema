<?php

namespace App\Livewire\Imports;

use App\Jobs\ProcessImportBatch;
use App\Models\ImportBatch;
use App\Services\Import\ImportService;
use App\Services\Import\Mapping\HeaderMatcher;
use App\Services\Import\Support\Text;
use App\Services\Import\Template\TemplateColumn;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Asistente de importación en 2 pasos:
 *   1. Subir archivo del cliente + plantilla del CMMS.
 *   2. Revisar tablas detectadas y mapeo sugerido, ajustar y procesar.
 * El resultado se muestra en ImportShow.
 *
 * @phpstan-import-type TemplateColumnArray from TemplateColumn
 *
 * @property-read ImportBatch|null $batch
 * @property-read array<string, string> $sourceHeaders
 * @property-read list<TemplateColumnArray> $columns
 */
#[Title('Importar activos')]
class ImportWizard extends Component
{
    use WithFileUploads;

    public string $step = 'upload';

    #[Validate('required|file|mimes:xlsx,xls,csv,txt|max:20480', as: 'archivo del cliente')]
    public ?TemporaryUploadedFile $sourceFile = null;

    #[Validate('required|file|mimes:xlsx,xlsm,xls|max:20480', as: 'plantilla del CMMS')]
    public ?TemporaryUploadedFile $templateFile = null;

    #[Locked]
    public ?int $batchId = null;

    /** @var list<string> */
    public array $selectedTables = [];

    /** @var array<string, array{mode: string, sources: list<string>, fixed: string, score?: float, reason?: string, manual?: bool}> */
    public array $mapping = [];

    /** @var list<string> */
    public array $extras = [];

    public bool $expandRanges = true;

    public string $codeMode = 'fill';

    public ?string $analysisError = null;

    public function mount(?ImportBatch $batch = null): void
    {
        if ($batch?->exists && $batch->user_id === auth()->id() && $batch->template_schema) {
            // Reabrir un lote para reprocesarlo con otro mapeo.
            $this->batchId = $batch->id;
            $this->selectedTables = $batch->settings['tables'] ?? [];
            $this->mapping = $batch->mapping ?? [];
            $this->extras = $batch->settings['extras'] ?? [];
            $this->expandRanges = (bool) ($batch->settings['expand_ranges'] ?? true);
            $this->codeMode = $batch->settings['code_mode'] ?? 'fill';
            $this->step = 'configure';
        }
    }

    public function analyze(ImportService $service): void
    {
        $this->validate();
        $this->analysisError = null;

        $batch = ImportBatch::create([
            'user_id' => auth()->id(),
            'status' => ImportBatch::STATUS_DRAFT,
            'source_name' => $this->sourceFile->getClientOriginalName(),
            'template_name' => $this->templateFile->getClientOriginalName(),
            'source_path' => '',
            'template_path' => '',
        ]);

        $disk = config('importador.disk', 'local');
        $dir = $batch->directory();

        $batch->update([
            'source_path' => $this->sourceFile->storeAs($dir, 'cliente.'.$this->extension($this->sourceFile), $disk),
            'template_path' => $this->templateFile->storeAs($dir, 'plantilla.'.$this->extension($this->templateFile), $disk),
        ]);

        try {
            $service->analyze($batch);
        } catch (Throwable $e) {
            report($e);
            $batch->delete();
            Storage::disk($disk)->deleteDirectory($dir);
            $this->analysisError = 'No se pudo leer los archivos: '.$e->getMessage();

            return;
        }

        $this->batchId = $batch->id;
        unset($this->batch);
        $this->selectedTables = $this->preselectTables($service, $batch->fresh());
        $this->applySuggestion($service);
        $this->reset('sourceFile', 'templateFile');
        $this->step = 'configure';
    }

    public function updatedSelectedTables(): void
    {
        $this->applySuggestion(app(ImportService::class), keepFixed: true);
    }

    /**
     * Recalcula el mapeo sugerido. Los valores fijos ya elegidos por el usuario se conservan.
     */
    public function applySuggestion(ImportService $service, bool $keepFixed = false): void
    {
        if ($this->selectedTables === []) {
            $this->extras = [];

            return;
        }

        $previous = $this->mapping;
        $suggestion = $service->suggest($this->batch, $this->selectedTables);
        $this->mapping = $suggestion['mapping'];
        $this->extras = $suggestion['extras'];

        if ($keepFixed) {
            foreach ($previous as $key => $entry) {
                if ($entry['mode'] === 'fixed' && $entry['fixed'] !== '' && isset($this->mapping[$key])) {
                    $this->mapping[$key] = $entry;
                }
            }
        }
    }

    public function resetSuggestion(ImportService $service): void
    {
        $this->applySuggestion($service);
    }

    public function setMode(string $key, string $mode): void
    {
        if (! isset($this->mapping[$key]) || ! in_array($mode, ['source', 'fixed', 'none'], true)) {
            return;
        }

        $this->mapping[$key]['mode'] = $mode;
        $this->markManual($key);

        if ($mode === 'source' && $this->mapping[$key]['sources'] === []) {
            $this->mapping[$key]['sources'] = [''];
        }
    }

    public function addSource(string $key): void
    {
        if (isset($this->mapping[$key])) {
            $this->mapping[$key]['sources'][] = '';
            $this->markManual($key);
        }
    }

    public function removeSource(string $key, int $index): void
    {
        if (! isset($this->mapping[$key]['sources'][$index])) {
            return;
        }

        $entry = $this->mapping[$key];
        unset($entry['sources'][$index]);
        $entry['sources'] = array_values($entry['sources']);

        if ($entry['sources'] === []) {
            $entry['mode'] = 'none';
        }

        $this->mapping[$key] = $entry;
        $this->markManual($key);
    }

    public function updatedMapping(mixed $value, string $path): void
    {
        // "nombre.sources.0" => al elegir una columna manualmente se marca como ajuste manual.
        $key = Str::before($path, '.');
        if (isset($this->mapping[$key])) {
            $this->markManual($key);
        }
    }

    protected function markManual(string $key): void
    {
        $entry = $this->mapping[$key];
        $entry['reason'] = 'Ajustado manualmente';
        $entry['score'] = 1.0;
        $entry['manual'] = true;
        $this->mapping[$key] = $entry;
    }

    public function process(): void
    {
        $batch = $this->batch;

        if ($this->selectedTables === []) {
            $this->addError('selectedTables', 'Seleccione al menos una tabla del archivo del cliente.');

            return;
        }

        $mapping = [];
        foreach ($this->mapping as $key => $entry) {
            $sources = array_values(array_filter($entry['sources'], fn ($s) => $s !== ''));
            $mode = $entry['mode'];
            if ($mode === 'source' && $sources === []) {
                $mode = 'none';
            }
            $mapping[$key] = [
                'mode' => $mode,
                'sources' => $sources,
                'fixed' => $entry['fixed'],
                'manual' => (bool) ($entry['manual'] ?? false),
            ];
        }

        $batch->update([
            'mapping' => $mapping,
            'settings' => [
                'tables' => $this->selectedTables,
                'extras' => $this->extras,
                'expand_ranges' => $this->expandRanges,
                'code_mode' => $this->codeMode,
            ],
            'status' => ImportBatch::STATUS_PROCESSING,
            'stats' => null,
            'result_rows' => null,
        ]);

        ProcessImportBatch::dispatch($batch);

        $this->redirectRoute('imports.show', $batch, navigate: true);
    }

    public function startOver(): void
    {
        $this->reset();
    }

    #[Computed]
    public function batch(): ?ImportBatch
    {
        if ($this->batchId === null) {
            return null;
        }

        return ImportBatch::query()->whereKey($this->batchId)->where('user_id', auth()->id())->firstOrFail();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function sourceHeaders(): array
    {
        return $this->batch ? app(ImportService::class)->sourceHeaders($this->batch, $this->selectedTables) : [];
    }

    /**
     * Columnas de la plantilla como arreglos para la vista.
     *
     * @return list<TemplateColumnArray>
     */
    #[Computed]
    public function columns(): array
    {
        return $this->batch?->template_schema['columns'] ?? [];
    }

    /**
     * Primeros valores de una columna del cliente (vista previa del mapeo).
     *
     * @param  list<string>  $sources
     */
    public function preview(array $sources): string
    {
        $values = [];

        foreach ($this->batch->source_tables as $table) {
            if (! in_array($table['id'], $this->selectedTables, true)) {
                continue;
            }

            $index = [];
            foreach ($table['headers'] as $col => $label) {
                $index[Text::normalize($label)] = $col;
            }

            foreach ($table['sample'] as $row) {
                $parts = [];
                foreach ($sources as $source) {
                    $col = $index[$source] ?? null;
                    if ($col !== null && ($row[$col] ?? '') !== '') {
                        $parts[] = $row[$col];
                    }
                }
                if ($parts !== []) {
                    $values[] = implode(' - ', $parts);
                }
                if (count($values) >= 3) {
                    break 2;
                }
            }
        }

        return implode(' · ', array_unique($values));
    }

    /**
     * Columnas del cliente que se pueden ofrecer para OBSERVACIONES (sin meses, montos ni IDs).
     *
     * @return array<string, string>
     */
    public function extraCandidates(): array
    {
        $used = [];
        foreach ($this->mapping as $entry) {
            if ($entry['mode'] === 'source') {
                foreach ($entry['sources'] as $s) {
                    $used[$s] = true;
                }
            }
        }

        $matcher = app(HeaderMatcher::class);

        return array_filter(
            $this->sourceHeaders,
            fn ($label, $key) => ! isset($used[$key]) && (! $matcher->isNoise($key) || in_array($key, $this->extras, true)),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * @return list<string>
     */
    public function unmappedRequired(): array
    {
        $missing = [];

        foreach ($this->columns as $column) {
            if (! $column['required'] || $column['fixed'] !== null) {
                continue;
            }

            $entry = $this->mapping[$column['key']] ?? null;
            $ok = match ($entry['mode'] ?? 'none') {
                'source' => array_filter($entry['sources']) !== [],
                'fixed' => $entry['fixed'] !== '',
                default => false,
            };

            // El código puede generarse automáticamente.
            if (! $ok && $column['role'] === TemplateColumn::ROLE_CODIGO && $this->codeMode !== 'keep') {
                $ok = true;
            }

            if (! $ok) {
                $missing[] = $column['label'];
            }
        }

        return $missing;
    }

    /**
     * Elige la tabla que mejor calza con la plantilla y suma las que tienen encabezados parecidos
     * (ej. "Generadores" y "Bombas" en la misma hoja).
     *
     * @return list<string>
     */
    protected function preselectTables(ImportService $service, ImportBatch $batch): array
    {
        $tables = $batch->source_tables ?? [];
        if (count($tables) <= 1) {
            return array_column($tables, 'id');
        }

        $best = null;
        $bestScore = -1.0;

        foreach ($tables as $table) {
            $suggestion = $service->suggest($batch, [$table['id']])['mapping'];
            $score = 0.0;
            foreach ($suggestion as $entry) {
                if ($entry['mode'] === 'source') {
                    $score += $entry['score'];
                }
            }
            $score += min(1, $table['row_count'] / 100) * 0.5;

            // La hoja o el título se llaman como la categoría de la plantilla (ej. "Tableros Electricos").
            $category = $batch->template_schema['category'] ?? '';
            if ($category !== '' && max(Text::similarity($table['sheet'], $category), Text::similarity($table['title'] ?? '', $category)) >= 0.8) {
                $score += 3;
            }

            if ($score > $bestScore) {
                [$best, $bestScore] = [$table, $score];
            }
        }

        $bestHeaders = array_map([Text::class, 'normalize'], $best['headers']);
        $selected = [];

        foreach ($tables as $table) {
            $headers = array_map([Text::class, 'normalize'], $table['headers']);
            $overlap = count(array_intersect($headers, $bestHeaders)) / max(1, count(array_unique(array_merge($headers, $bestHeaders))));
            if ($table['id'] === $best['id'] || ($table['sheet'] === $best['sheet'] && $overlap >= 0.5)) {
                $selected[] = $table['id'];
            }
        }

        return $selected;
    }

    protected function extension(TemporaryUploadedFile $file): string
    {
        return strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'xlsx');
    }

    public function render(): View
    {
        return view('livewire.imports.import-wizard');
    }
}
