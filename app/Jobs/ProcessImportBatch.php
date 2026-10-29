<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Services\Import\ImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Procesa una importación en segundo plano (archivos grandes no bloquean la pantalla).
 * Con QUEUE_CONNECTION=sync se ejecuta al instante, sin necesidad de un worker.
 */
class ProcessImportBatch implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public ImportBatch $batch) {}

    public function handle(ImportService $service): void
    {
        $service->process($this->batch);
    }
}
