<?php

namespace App\Http\Controllers;

use App\Models\ImportBatch;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportDownloadController extends Controller
{
    /**
     * Descarga la plantilla llena: "result" (con columna de validación) o "clean" (sólo filas válidas).
     */
    public function __invoke(ImportBatch $batch, string $type): StreamedResponse
    {
        abort_unless($batch->user_id === auth()->id(), 403);
        abort_unless($batch->status === ImportBatch::STATUS_COMPLETED, 404);

        $path = match ($type) {
            'result' => $batch->result_path,
            'clean' => $batch->clean_path,
            default => abort(404),
        };

        $disk = Storage::disk(config('importador.disk', 'local'));
        abort_unless($path && $disk->exists($path), 404);

        return $disk->download($path, basename($path));
    }
}
