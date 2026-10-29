<?php

use App\Services\Import\Source\SourceReader;

beforeEach(function () {
    foreach (['cliente_gantt_indisa.xlsx', 'cliente_equipos_ediser.xlsx', 'plantilla_abatidor.xlsx', 'plantilla_tableros.xlsx'] as $file) {
        if (! file_exists(base_path('tests/Fixtures/'.$file))) {
            $this->markTestSkipped('Faltan los archivos de ejemplo en tests/Fixtures (ver tests/Fixtures/README.md).');
        }
    }
});

it('detects tables, multi-row headers and merged cells in a messy client file', function () {
    $tables = (new SourceReader)->read(base_path('tests/Fixtures/cliente_equipos_ediser.xlsx'));
    $byId = collect($tables)->keyBy('id');

    // Dos tablas en la hoja "Generadores" (generadores y bombas).
    expect($byId['0-0']->sheet)->toBe('Generadores')
        ->and($byId['0-0']->rows)->toHaveCount(3)
        ->and($byId['0-1']->rows)->toHaveCount(2)
        ->and(array_values($byId['0-1']->headers))->toContain('Caudal de Agua L/M', 'POTENCIA ELECTRICA KW');

    // Encabezado en dos filas: "N°" + "Activo".
    $iluminacion = $byId['1-0'];
    expect(array_values($iluminacion->headers))->toContain('N° Activo', 'Consumo en Watt')
        ->and($iluminacion->rows)->toHaveCount(64);

    // Piso combinado verticalmente: se repite en cada fila.
    expect($iluminacion->rows[1]['values'][1])->toBe('6°');
});

it('skips titles, legends and totals rows', function () {
    $tables = (new SourceReader)->read(base_path('tests/Fixtures/cliente_gantt_indisa.xlsx'));

    expect($tables)->toHaveCount(1)
        ->and($tables[0]->headerRows)->toBe([5])
        ->and($tables[0]->title)->toContain('GANTT DETALLADO')
        ->and($tables[0]->rows)->toHaveCount(94);
});
