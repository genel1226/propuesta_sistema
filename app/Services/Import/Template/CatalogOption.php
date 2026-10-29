<?php

namespace App\Services\Import\Template;

use App\Services\Import\Support\Text;

/**
 * Un valor permitido de un catálogo de la plantilla.
 *
 * En las plantillas del CMMS los catálogos vienen como "Etiqueta|ID"
 * (ej. "Operativo|b3BlcmF0aXZv"). En la plantilla llena se escribe el valor
 * completo (`value`), pero para comparar se usa la etiqueta.
 *
 * Las sub ubicaciones traen además jerarquía:
 * "[Cliente] X - [Ubicación] 10º PISO - [Sub ubicación] ORIENTE |ID".
 *
 * @phpstan-type CatalogOptionArray array{value: string, label: string, id?: string, cliente?: string, ubicacion?: string, sub?: string}
 */
final class CatalogOption
{
    public function __construct(
        public readonly string $value,
        public readonly string $label,
        public readonly ?string $id = null,
        public readonly ?string $cliente = null,
        public readonly ?string $ubicacion = null,
        public readonly ?string $subUbicacion = null,
    ) {}

    public static function fromCell(string $raw): self
    {
        $raw = trim($raw);
        $label = $raw;
        $id = null;

        $pos = strrpos($raw, '|');
        if ($pos !== false) {
            $label = trim(substr($raw, 0, $pos));
            $id = trim(substr($raw, $pos + 1));
        }

        if (preg_match('/\[Cliente\]\s*(.*?)\s*-\s*\[Ubicaci[oó]n\]\s*(.*?)\s*-\s*\[Sub ubicaci[oó]n\]\s*(.*)$/iu', $label, $m)) {
            return new self($raw, $label, $id, trim($m[1]), trim($m[2]), trim($m[3]));
        }

        return new self($raw, $label, $id);
    }

    /**
     * Texto con el que se compara un valor del cliente contra esta opción.
     */
    public function matchText(): string
    {
        if ($this->subUbicacion !== null) {
            return trim($this->ubicacion.' '.$this->subUbicacion);
        }

        return $this->label;
    }

    /**
     * Etiqueta corta para mostrar en pantalla.
     */
    public function displayLabel(): string
    {
        if ($this->subUbicacion !== null) {
            return $this->ubicacion.' › '.$this->subUbicacion;
        }

        return $this->label;
    }

    public function belongsToCliente(?string $clienteLabel): bool
    {
        if ($this->cliente === null || $clienteLabel === null || $clienteLabel === '') {
            return true;
        }

        return Text::normalize($this->cliente) === Text::normalize($clienteLabel);
    }

    /**
     * @return CatalogOptionArray
     */
    public function toArray(): array
    {
        return array_filter([
            'value' => $this->value,
            'label' => $this->label,
            'id' => $this->id,
            'cliente' => $this->cliente,
            'ubicacion' => $this->ubicacion,
            'sub' => $this->subUbicacion,
        ], fn ($v) => $v !== null);
    }

    /**
     * @param  CatalogOptionArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['value'],
            $data['label'],
            $data['id'] ?? null,
            $data['cliente'] ?? null,
            $data['ubicacion'] ?? null,
            $data['sub'] ?? null,
        );
    }
}
