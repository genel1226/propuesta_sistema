<?php

namespace App\Livewire\Imports;

use App\Models\ImportBatch;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Historial de importaciones')]
class ImportIndex extends Component
{
    use WithPagination;

    public function render(): View
    {
        return view('livewire.imports.import-index', [
            'batches' => ImportBatch::query()
                ->where('user_id', auth()->id())
                ->whereNotNull('template_schema')
                ->latest()
                ->paginate(15, ['id', 'status', 'category', 'cliente', 'source_name', 'template_name', 'stats', 'created_at']),
        ]);
    }
}
