<?php

namespace App\Services\Import\Source;

/**
 * Una tabla detectada dentro del archivo del cliente.
 * Un mismo archivo puede tener varias hojas y varias tablas por hoja.
 *
 * @phpstan-type SourceTableSummary array{id: string, sheet: string, title: ?string, label: string, header_row: int, headers: array<int, string>, row_count: int, sample: list<array<int, string>>}
 */
final class SourceTable
{
    /**
     * @param  list<int>  $headerRows  Filas (1-based) que forman el encabezado.
     * @param  array<int, string>  $headers  Índice de columna (1-based) => encabezado combinado.
     * @param  list<array{row:int, values:array<int,string>}>  $rows
     */
    public function __construct(
        public readonly string $id,
        public readonly string $sheet,
        public readonly ?string $title,
        public readonly array $headerRows,
        public readonly array $headers,
        public readonly array $rows,
    ) {}

    public function label(): string
    {
        $label = $this->sheet;

        if ($this->title) {
            $label .= ' · '.$this->title;
        }

        return $label.' (fila '.$this->headerRows[0].')';
    }

    /**
     * Resumen liviano para guardar en el estado del componente Livewire.
     *
     * @return SourceTableSummary
     */
    public function summary(int $sampleSize = 5): array
    {
        return [
            'id' => $this->id,
            'sheet' => $this->sheet,
            'title' => $this->title,
            'label' => $this->label(),
            'header_row' => $this->headerRows[0],
            'headers' => $this->headers,
            'row_count' => count($this->rows),
            'sample' => array_map(fn ($r) => $r['values'], array_slice($this->rows, 0, $sampleSize)),
        ];
    }
}
