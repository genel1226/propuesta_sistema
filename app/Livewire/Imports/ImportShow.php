<?php

namespace App\Livewire\Imports;

use App\Models\ImportBatch;
use App\Services\Import\ImportService;
use App\Services\Import\Support\Text;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Resumen de una importación: estadísticas, observaciones por fila y descargas.
 *
 * @phpstan-import-type CompactRow from ImportService
 *
 * @property-read ImportBatch $batch
 * @property-read list<CompactRow> $filteredRows
 */
#[Title('Resultado de importación')]
class ImportShow extends Component
{
    #[Locked]
    public int $batchId;

    #[Url(as: 'estado')]
    public string $filter = 'all';

    #[Url(as: 'q')]
    public string $search = '';

    public int $perPage = 50;

    public int $page = 1;

    public function mount(ImportBatch $batch): void
    {
        abort_unless($batch->user_id === auth()->id(), 403);
        $this->batchId = $batch->id;
    }

    #[Computed]
    public function batch(): ImportBatch
    {
        return ImportBatch::query()->findOrFail($this->batchId);
    }

    public function updatedFilter(): void
    {
        $this->page = 1;
    }

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function refreshStatus(): void
    {
        unset($this->batch);
    }

    /**
     * @return list<CompactRow>
     */
    #[Computed]
    public function filteredRows(): array
    {
        $rows = $this->batch->result_rows ?? [];
        $search = Text::normalize($this->search);

        return array_values(array_filter($rows, function ($row) use ($search) {
            if ($this->filter !== 'all' && $row['status'] !== $this->filter) {
                return false;
            }

            if ($search === '') {
                return true;
            }

            $haystack = Text::normalize(implode(' ', $row['values']).' '.$row['origin'].' '.implode(' ', array_column($row['issues'], 'message')));

            return str_contains($haystack, $search);
        }));
    }

    public function nextPage(): void
    {
        if ($this->page * $this->perPage < count($this->filteredRows)) {
            $this->page++;
        }
    }

    public function previousPage(): void
    {
        $this->page = max(1, $this->page - 1);
    }

    public function render(): View
    {
        return view('livewire.imports.import-show', [
            'pageRows' => array_slice($this->filteredRows, ($this->page - 1) * $this->perPage, $this->perPage),
        ]);
    }
}
