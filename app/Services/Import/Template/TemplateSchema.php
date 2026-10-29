<?php

namespace App\Services\Import\Template;

/**
 * Estructura de una plantilla de importación del CMMS (columnas, reglas y catálogos).
 *
 * @phpstan-import-type TemplateColumnArray from TemplateColumn
 *
 * @phpstan-type TemplateSchemaArray array{sheet: string, header_row: int, data_start_row: int, title: ?string, category: ?string, columns: non-empty-list<TemplateColumnArray>}
 */
final class TemplateSchema
{
    /**
     * @param  non-empty-list<TemplateColumn>  $columns
     */
    public function __construct(
        public readonly string $sheetName,
        public readonly int $headerRow,
        public readonly int $dataStartRow,
        public readonly ?string $title,
        public readonly ?string $category,
        public readonly array $columns,
    ) {}

    public function column(string $key): ?TemplateColumn
    {
        foreach ($this->columns as $column) {
            if ($column->key === $key) {
                return $column;
            }
        }

        return null;
    }

    public function byRole(string $role): ?TemplateColumn
    {
        foreach ($this->columns as $column) {
            if ($column->role === $role) {
                return $column;
            }
        }

        return null;
    }

    public function lastColumnIndex(): int
    {
        return max(array_map(fn (TemplateColumn $c) => $c->index, $this->columns));
    }

    /**
     * Firma de la plantilla (sirve para reconocer plantillas con las mismas columnas).
     */
    public function signature(): string
    {
        return md5(implode('|', array_map(fn (TemplateColumn $c) => $c->key, $this->columns)));
    }

    /**
     * @return TemplateSchemaArray
     */
    public function toArray(): array
    {
        return [
            'sheet' => $this->sheetName,
            'header_row' => $this->headerRow,
            'data_start_row' => $this->dataStartRow,
            'title' => $this->title,
            'category' => $this->category,
            'columns' => array_map(fn (TemplateColumn $c) => $c->toArray(), $this->columns),
        ];
    }

    /**
     * @param  TemplateSchemaArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['sheet'],
            $data['header_row'],
            $data['data_start_row'],
            $data['title'],
            $data['category'],
            array_map(fn (array $c) => TemplateColumn::fromArray($c), $data['columns']),
        );
    }
}
