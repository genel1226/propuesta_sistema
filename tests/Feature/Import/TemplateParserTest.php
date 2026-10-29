<?php

use App\Services\Import\Template\TemplateColumn;
use App\Services\Import\Template\TemplateParser;

beforeEach(function () {
    foreach (['cliente_gantt_indisa.xlsx', 'cliente_equipos_ediser.xlsx', 'plantilla_abatidor.xlsx', 'plantilla_tableros.xlsx'] as $file) {
        if (! file_exists(base_path('tests/Fixtures/'.$file))) {
            $this->markTestSkipped('Faltan los archivos de ejemplo en tests/Fixtures (ver tests/Fixtures/README.md).');
        }
    }
});

function templateFixture(string $name): string
{
    return base_path('tests/Fixtures/'.$name);
}

it('reads columns, rules and catalogs from the CMMS template', function () {
    $schema = (new TemplateParser)->parse(templateFixture('plantilla_abatidor.xlsx'));

    expect($schema->sheetName)->toBe('ACTIVOS')
        ->and($schema->headerRow)->toBe(2)
        ->and($schema->dataStartRow)->toBe(3)
        ->and($schema->category)->toBe('ABATIDOR')
        ->and($schema->columns)->toHaveCount(13);

    $codigo = $schema->byRole(TemplateColumn::ROLE_CODIGO);
    expect($codigo->required)->toBeTrue()->and($codigo->unique)->toBeTrue();

    expect($schema->column('tipo de activo')->options)->toBe(['Mueble']);
    expect($schema->byRole(TemplateColumn::ROLE_ESTADO)->catalog)->toHaveCount(5);
    expect($schema->byRole(TemplateColumn::ROLE_CATEGORIA)->fixedValue)->toBe('oR91mRpR9');

    $sub = $schema->byRole(TemplateColumn::ROLE_SUB_UBICACION)->catalog[0];
    expect($sub->cliente)->toBe('Cafetería Bonmestre Clínica Las Condes')
        ->and($sub->ubicacion)->toBe('PISO -1')
        ->and($sub->subUbicacion)->toBe('Cocina Caliente')
        ->and($sub->id)->toBe('0A9E1ERp9');
});

it('adapts to templates customized with different columns', function () {
    $schema = (new TemplateParser)->parse(templateFixture('plantilla_tableros.xlsx'));

    expect(array_map(fn ($c) => $c->key, $schema->columns))->toBe([
        'nombre', 'localizacion', 'cliente', 'sub ubicacion', 'estado final del equipo', 'marca', 'observaciones', 'categoria',
    ]);
    expect($schema->byRole(TemplateColumn::ROLE_CODIGO))->toBeNull();
});
