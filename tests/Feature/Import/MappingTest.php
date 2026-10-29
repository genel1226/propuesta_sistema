<?php

use App\Services\Import\Mapping\CatalogMatcher;
use App\Services\Import\Mapping\HeaderMatcher;
use App\Services\Import\Source\SourceReader;
use App\Services\Import\Support\Text;
use App\Services\Import\Template\TemplateColumn;
use App\Services\Import\Template\TemplateParser;

beforeEach(function () {
    foreach (['cliente_gantt_indisa.xlsx', 'cliente_equipos_ediser.xlsx', 'plantilla_abatidor.xlsx', 'plantilla_tableros.xlsx'] as $file) {
        if (! file_exists(base_path('tests/Fixtures/'.$file))) {
            $this->markTestSkipped('Faltan los archivos de ejemplo en tests/Fixtures (ver tests/Fixtures/README.md).');
        }
    }
});

function headersOf(string $file, array $ids): array
{
    $headers = [];
    foreach ((new SourceReader)->read(base_path('tests/Fixtures/'.$file)) as $table) {
        if (in_array($table->id, $ids, true)) {
            foreach ($table->headers as $label) {
                $headers[Text::normalize($label)] = $label;
            }
        }
    }

    return $headers;
}

it('maps the Gantt headers to the ABATIDOR template', function () {
    $schema = (new TemplateParser)->parse(base_path('tests/Fixtures/plantilla_abatidor.xlsx'));
    $mapping = (new HeaderMatcher)->suggest($schema, headersOf('cliente_gantt_indisa.xlsx', ['0-0']));

    expect($mapping['nombre']['sources'])->toBe(['activo'])
        ->and($mapping['codigo']['sources'])->toBe(['n activo'])
        ->and($mapping['sub ubicacion']['sources'])->toBe(['piso', 'zona'])
        ->and($mapping['equipo']['sources'])->toBe(['categoria'])
        ->and($mapping['familia']['sources'])->toBe(['familia'])
        ->and($mapping['prioridad']['sources'])->toBe(['prioridad'])
        ->and($mapping['estado final del equipo']['mode'])->toBe('none');
});

it('never auto-maps month or amount columns', function () {
    $matcher = new HeaderMatcher;

    expect($matcher->isNoise('jun 26'))->toBeTrue()
        ->and($matcher->isNoise('valor unitario'))->toBeTrue()
        ->and($matcher->isNoise('id'))->toBeTrue()
        ->and($matcher->isNoise('marca'))->toBeFalse();
});

it('matches a floor + sector against the hierarchical sub-location catalog', function () {
    $schema = (new TemplateParser)->parse(base_path('tests/Fixtures/plantilla_tableros.xlsx'));
    $catalog = $schema->byRole(TemplateColumn::ROLE_SUB_UBICACION)->catalog;
    $matcher = new CatalogMatcher;

    $match = $matcher->match('6° - Común', $catalog, 'Edificio de Servicio');
    expect($match['option']->ubicacion)->toBe('6° PISO')
        ->and($match['option']->subUbicacion)->toBe('COMÚN');

    // Mismo texto pero distinto piso: no debe confundirse.
    $match = $matcher->match('5° - Norte', $catalog, 'Edificio de Servicio');
    expect($match['option']->ubicacion)->toBe('5° PISO');

    // No existe en el catálogo.
    expect($matcher->match('Azotea - Sala de máquinas', $catalog, 'Edificio de Servicio')['option'])->toBeNull();
});
