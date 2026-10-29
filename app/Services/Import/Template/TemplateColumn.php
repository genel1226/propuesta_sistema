<?php

namespace App\Services\Import\Template;

/**
 * Una columna de la plantilla del CMMS, con las reglas que se leyeron de su encabezado.
 *
 * @phpstan-import-type CatalogOptionArray from CatalogOption
 *
 * @phpstan-type TemplateColumnArray array{index: int, letter: string, key: string, label: string, raw: string, required: bool, unique: bool, catalog: list<CatalogOptionArray>, options: list<string>, fixed: ?string, role: ?string}
 */
final class TemplateColumn
{
    public const ROLE_NOMBRE = 'nombre';

    public const ROLE_CODIGO = 'codigo';

    public const ROLE_CLIENTE = 'cliente';

    public const ROLE_LOCALIZACION = 'localizacion';

    public const ROLE_SUB_UBICACION = 'sub_ubicacion';

    public const ROLE_ESTADO = 'estado';

    public const ROLE_CATEGORIA = 'categoria';

    public const ROLE_OBSERVACIONES = 'observaciones';

    /**
     * @param  list<CatalogOption>  $catalog  Valores permitidos (lista desplegable de la plantilla).
     * @param  list<string>  $options  Opciones escritas en el propio encabezado ("Ingrese una de las siguientes opciones").
     */
    public function __construct(
        public readonly int $index,
        public readonly string $letter,
        public readonly string $key,
        public readonly string $label,
        public readonly string $rawHeader,
        public readonly bool $required = false,
        public readonly bool $unique = false,
        public readonly array $catalog = [],
        public readonly array $options = [],
        public readonly ?string $fixedValue = null,
        public readonly ?string $role = null,
    ) {}

    public function hasCatalog(): bool
    {
        return $this->catalog !== [];
    }

    public function hasOptions(): bool
    {
        return $this->options !== [];
    }

    /**
     * @phpstan-assert-if-true !null $this->fixedValue
     */
    public function isFixed(): bool
    {
        return $this->fixedValue !== null;
    }

    /**
     * @return TemplateColumnArray
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'letter' => $this->letter,
            'key' => $this->key,
            'label' => $this->label,
            'raw' => $this->rawHeader,
            'required' => $this->required,
            'unique' => $this->unique,
            'catalog' => array_map(fn (CatalogOption $o) => $o->toArray(), $this->catalog),
            'options' => $this->options,
            'fixed' => $this->fixedValue,
            'role' => $this->role,
        ];
    }

    /**
     * @param  TemplateColumnArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['index'],
            $data['letter'],
            $data['key'],
            $data['label'],
            $data['raw'],
            $data['required'],
            $data['unique'],
            array_map(fn (array $o) => CatalogOption::fromArray($o), $data['catalog']),
            $data['options'],
            $data['fixed'],
            $data['role'],
        );
    }
}
