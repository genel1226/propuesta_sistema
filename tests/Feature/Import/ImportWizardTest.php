<?php

use App\Livewire\Imports\ImportShow;
use App\Livewire\Imports\ImportWizard;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    foreach (['cliente_gantt_indisa.xlsx', 'cliente_equipos_ediser.xlsx', 'plantilla_abatidor.xlsx', 'plantilla_tableros.xlsx'] as $file) {
        if (! file_exists(base_path('tests/Fixtures/'.$file))) {
            $this->markTestSkipped('Faltan los archivos de ejemplo en tests/Fixtures (ver tests/Fixtures/README.md).');
        }
    }
});

it('runs the full flow: upload, map, process and download', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $this->actingAs($user);

    $source = UploadedFile::fake()->createWithContent('Equipos Ediser 2026.xlsx', file_get_contents(base_path('tests/Fixtures/cliente_equipos_ediser.xlsx')));
    $template = UploadedFile::fake()->createWithContent('Plantilla TABLEROS.xlsx', file_get_contents(base_path('tests/Fixtures/plantilla_tableros.xlsx')));

    $component = Livewire::test(ImportWizard::class)
        ->set('sourceFile', $source)
        ->set('templateFile', $template)
        ->call('analyze')
        ->assertHasNoErrors()
        ->assertSet('step', 'configure')
        ->assertSee('Mapeo de columnas');

    // La tabla "Tableros Electricos" se preselecciona por parecerse a la categoría de la plantilla.
    $batch = ImportBatch::firstOrFail();
    $tableros = collect($batch->source_tables)->firstWhere('sheet', 'Tableros Electricos');
    expect($component->get('selectedTables'))->toBe([$tableros['id']]);

    // Cliente y localización se deducen del título "EDIFICIO DE SERVICIOS".
    expect($component->get('mapping.cliente.fixed'))->toStartWith('Edificio de Servicio|');

    $component->call('process')->assertRedirect(route('imports.show', $batch));

    $batch->refresh();
    expect($batch->status)->toBe('completed')
        ->and($batch->stats['total'])->toBe(21)
        ->and($batch->stats['error'])->toBe(0);

    Livewire::test(ImportShow::class, ['batch' => $batch])->assertSee('Sin observaciones')->assertSee('TD COMP 6°');

    $this->get(route('imports.download', [$batch, 'result']))->assertOk()->assertDownload();
    $this->get(route('imports.download', [$batch, 'clean']))->assertOk()->assertDownload();
});

it('does not let users see other users imports', function () {
    $owner = User::factory()->create();
    $batch = ImportBatch::create([
        'user_id' => $owner->id, 'status' => 'completed', 'source_name' => 'a.xlsx', 'template_name' => 'b.xlsx',
        'source_path' => 'x', 'template_path' => 'y', 'result_path' => 'z',
    ]);

    $this->actingAs(User::factory()->create());
    $this->get(route('imports.show', $batch))->assertForbidden();
    $this->get(route('imports.download', [$batch, 'result']))->assertForbidden();
});
