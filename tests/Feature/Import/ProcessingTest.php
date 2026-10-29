<?php

use App\Services\Import\Export\TemplateFiller;
use App\Services\Import\Mapping\HeaderMatcher;
use App\Services\Import\Processing\CodeGenerator;
use App\Services\Import\Processing\ImportProcessor;
use App\Services\Import\Processing\ImportResult;
use App\Services\Import\Processing\RangeExpander;
use App\Services\Import\Source\SourceReader;
use App\Services\Import\Support\Text;
use App\Services\Import\Template\TemplateParser;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function () {
    foreach (['cliente_gantt_indisa.xlsx', 'cliente_equipos_ediser.xlsx', 'plantilla_abatidor.xlsx', 'plantilla_tableros.xlsx'] as $file) {
        if (! file_exists(base_path('tests/Fixtures/'.$file))) {
            $this->markTestSkipped('Faltan los archivos de ejemplo en tests/Fixtures (ver tests/Fixtures/README.md).');
        }
    }
});

function processFixture(string $template, string $source, array $tableIds, array $fixed, array $settings = []): array
{
    $schema = (new TemplateParser)->parse(base_path('tests/Fixtures/'.$template));
    $tables = array_values(array_filter(
        (new SourceReader)->read(base_path('tests/Fixtures/'.$source)),
        fn ($t) => in_array($t->id, $tableIds, true)
    ));

    $headers = [];
    foreach ($tables as $t) {
        foreach ($t->headers as $h) {
            $headers[Text::normalize($h)] = $h;
        }
    }

    $mapping = (new HeaderMatcher)->suggest($schema, $headers);
    foreach ($fixed as $key => $labelPrefix) {
        foreach ($schema->column($key)->catalog as $option) {
            if (str_starts_with($option->label, $labelPrefix)) {
                $mapping[$key] = ['mode' => 'fixed', 'sources' => [], 'fixed' => $option->value];
                break;
            }
        }
    }

    $result = (new ImportProcessor)->process($schema, $tables, $mapping, $settings + ['expand_ranges' => true, 'code_mode' => 'fill'], $headers);

    return [$schema, $result];
}

it('generates codes as name abbreviation + correlative', function () {
    $generator = new CodeGenerator(['VF-01']);

    expect($generator->generate('Vitrina de frio'))->toBe('VF-02')
        ->and($generator->generate('Vitrina de frío'))->toBe('VF-03')
        ->and($generator->generate('Abatidor'))->toBe('ABA-01')
        ->and($generator->generate('Unidad Condensadora'))->toBe('UC-01');
});

it('expands ranges like "SP6NM01 AL 28"', function () {
    $expander = new RangeExpander;

    expect($expander->expand('SP6NM01 AL 28'))->toHaveCount(28)
        ->and($expander->expand('SP6NM01 AL 28')[27])->toBe('SP6NM28')
        ->and($expander->expand('SP4SK01'))->toBeNull()
        ->and($expander->expand('TDF y A 5°'))->toBeNull();
});

it('fills the TABLEROS template from the client file without errors', function () {
    [, $result] = processFixture('plantilla_tableros.xlsx', 'cliente_equipos_ediser.xlsx', ['2-0'], [
        'cliente' => 'Edificio de Servicio',
        'localizacion' => 'Edificio de Servicio',
        'estado final del equipo' => 'Operativo',
    ]);

    $stats = $result->stats();
    expect($stats['total'])->toBe(21)->and($stats['error'])->toBe(0);

    // Se escribe el valor completo "Etiqueta|ID" del catálogo.
    expect($result->rows[0]['values'][4])->toBe('[Cliente] Edificio de Servicio - [Ubicación] 6° PISO - [Sub ubicación] COMÚN |AR9o11Rm9')
        ->and($result->rows[0]['values'][8])->toBe('p19epmE19');
});

it('flags missing required fields, duplicates and out-of-catalog values', function () {
    [, $result] = processFixture('plantilla_abatidor.xlsx', 'cliente_equipos_ediser.xlsx', ['1-0'], [
        'localizacion' => 'Santiago',
        'estado final del equipo' => 'Operativo',
    ]);

    $types = array_keys($result->stats()['by_message']);

    expect($types)->toContain('error|CLIENTE|Campo obligatorio vacío')
        ->and($types)->toContain('error|CÓDIGO|Valor duplicado')
        ->and($types)->toContain('error|SUB UBICACIÓN|Valor fuera de catálogo')
        ->and($result->stats()['expanded_rows'])->toBeGreaterThan(800);
});

it('writes a template with a validation column and a clean template', function () {
    [$schema, $result] = processFixture('plantilla_tableros.xlsx', 'cliente_equipos_ediser.xlsx', ['0-0', '0-1'], [
        'cliente' => 'Edificio de Servicio',
        'localizacion' => 'Edificio de Servicio',
        'estado final del equipo' => 'Operativo',
    ]);

    $dir = sys_get_temp_dir();
    $filler = new TemplateFiller;
    $filler->write(base_path('tests/Fixtures/plantilla_tableros.xlsx'), $schema, $result, $dir.'/with.xlsx', true);
    $filler->write(base_path('tests/Fixtures/plantilla_tableros.xlsx'), $schema, $result, $dir.'/clean.xlsx', false);

    $with = IOFactory::load($dir.'/with.xlsx');
    $sheet = $with->getSheetByName('ACTIVOS');
    expect($sheet->getCell('A3')->getValue())->toBe('GENERADOR 1')
        ->and($sheet->getCell('I2')->getValue())->toBe(TemplateFiller::VALIDATION_HEADER)
        ->and($with->getSheetByName('PARÁMETROS (No tocar)'))->not->toBeNull()
        ->and($with->getSheetByName('RESUMEN VALIDACIÓN'))->not->toBeNull()
        ->and($sheet->getDataValidation('B3'))->not->toBeNull();

    $clean = IOFactory::load($dir.'/clean.xlsx')->getSheetByName('ACTIVOS');
    expect($clean->getCell('I2')->getValue())->toBeNull();
    expect(ImportResult::describe($result->rows[0]))->toBe('OK');
});
