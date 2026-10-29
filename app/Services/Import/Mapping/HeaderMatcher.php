<?php

namespace App\Services\Import\Mapping;

use App\Services\Import\Support\Text;
use App\Services\Import\Template\TemplateColumn;
use App\Services\Import\Template\TemplateSchema;

/**
 * Mapeo inteligente: propone qué columna(s) del cliente alimentan cada columna de la plantilla.
 *
 * Orden de confianza:
 *   1. Mismo nombre normalizado                              -> 1.00
 *   2. Correcciones manuales aprendidas de otros usuarios    -> 0.97
 *   3. Sinónimo exacto del diccionario (config/importador)   -> 0.95
 *   4. Similitud de texto con el nombre o sus sinónimos      -> 0..0.9
 *
 * La asignación es "golosa": primero se fijan los pares con mayor puntaje y cada
 * columna del cliente se usa una sola vez; en una segunda pasada los campos
 * obligatorios que quedaron sin mapear pueden reutilizar una columna.
 *
 * @phpstan-type SuggestedEntry array{mode: string, sources: list<string>, fixed: string, score: float, reason: string}
 */
class HeaderMatcher
{
    /**
     * @param  array<string, string>  $sourceHeaders  clave normalizada => encabezado visible
     * @param  array<string, string>  $learned  encabezado normalizado del cliente => clave de plantilla
     * @return array<string, SuggestedEntry>
     */
    public function suggest(TemplateSchema $schema, array $sourceHeaders, array $learned = []): array
    {
        $mapping = [];
        $available = $sourceHeaders;

        foreach ($schema->columns as $column) {
            $mapping[$column->key] = $this->empty();
        }

        // Sub ubicación: se arma combinando "Piso/Ubicación" + "Sector/Zona" si existen.
        $sub = $schema->byRole(TemplateColumn::ROLE_SUB_UBICACION);
        if ($sub !== null) {
            $sources = $this->multiSource($sub->key, $available, $learned);
            if ($sources !== []) {
                $mapping[$sub->key] = ['mode' => 'source', 'sources' => $sources, 'fixed' => '', 'score' => 0.9, 'reason' => 'Combinación de ubicación + sector'];
                foreach ($sources as $s) {
                    unset($available[$s]);
                }
            }
        }

        $candidates = [];
        foreach ($schema->columns as $column) {
            if ($column->isFixed() || $mapping[$column->key]['sources'] !== []) {
                continue;
            }

            foreach ($available as $sourceKey => $label) {
                [$score, $reason] = $this->score($column, $sourceKey, $learned);
                if ($score >= (float) config('importador.match_threshold', 0.62)) {
                    $candidates[] = [$column, $sourceKey, $score, $reason];
                }
            }
        }

        usort($candidates, fn ($a, $b) => $b[2] <=> $a[2]);

        $used = [];
        foreach ($candidates as [$column, $sourceKey, $score, $reason]) {
            if ($mapping[$column->key]['sources'] !== [] || isset($used[$sourceKey])) {
                continue;
            }

            $mapping[$column->key] = ['mode' => 'source', 'sources' => [$sourceKey], 'fixed' => '', 'score' => $score, 'reason' => $reason];
            $used[$sourceKey] = true;
        }

        // Segunda pasada: obligatorios vacíos pueden reutilizar una columna ya usada.
        foreach ($candidates as [$column, $sourceKey, $score, $reason]) {
            if ($column->role === TemplateColumn::ROLE_NOMBRE && $mapping[$column->key]['sources'] === []) {
                $mapping[$column->key] = ['mode' => 'source', 'sources' => [$sourceKey], 'fixed' => '', 'score' => round($score * 0.95, 4), 'reason' => $reason.' (columna compartida)'];
            }
        }

        return $mapping;
    }

    /**
     * @param  array<string, string>  $learned
     * @return array{0: float, 1: string}
     */
    public function score(TemplateColumn $column, string $sourceKey, array $learned = []): array
    {
        if ($this->isNoise($sourceKey)) {
            return [0.0, ''];
        }

        if ($sourceKey === $column->key) {
            return [1.0, 'Mismo nombre'];
        }

        if (($learned[$sourceKey] ?? null) === $column->key) {
            return [0.97, 'Corrección aprendida de importaciones anteriores'];
        }

        $synonyms = $this->synonymsFor($column->key);

        if (in_array($sourceKey, $synonyms, true)) {
            return [0.95, 'Sinónimo conocido'];
        }

        $best = Text::similarity($sourceKey, $column->key);
        foreach ($synonyms as $synonym) {
            $best = max($best, Text::similarity($sourceKey, $synonym));
        }

        // Columnas con catálogo (cliente, estado...) sólo se mapean por nombre exacto o sinónimo:
        // un parecido parcial ("Estado final del equipo" ~ "Equipo") trae valores que no calzan.
        if ($column->hasCatalog()) {
            return [0.0, ''];
        }

        return [round($best * 0.9, 4), 'Similitud de texto'];
    }

    /**
     * @return list<string>
     */
    public function synonymsFor(string $templateKey): array
    {
        $all = config('importador.synonyms', []);

        if (isset($all[$templateKey])) {
            return $all[$templateKey];
        }

        foreach ($all as $key => $synonyms) {
            if (str_starts_with($templateKey, $key.' ') || str_starts_with($key, $templateKey.' ')) {
                return $synonyms;
            }
        }

        return [];
    }

    /**
     * Columnas que nunca se proponen automáticamente: meses, montos, IDs correlativos, totales.
     */
    public function isNoise(string $sourceKey): bool
    {
        return $sourceKey === 'id'
            || $sourceKey === 'n'
            || $sourceKey === 'item n'
            || (bool) preg_match('/^(ene|feb|mar|abr|may|jun|jul|ago|sep|set|oct|nov|dic)[a-z]* \d{2,4}\b/', $sourceKey)
            || (bool) preg_match('/^\d{4} \d{2} \d{2}$/', $sourceKey)
            || (bool) preg_match('/\b(total|valor|precio|costo|monto)\b/', $sourceKey);
    }

    /**
     * @param  array<string, string>  $available
     * @param  array<string, string>  $learned
     * @return list<string>
     */
    protected function multiSource(string $templateKey, array $available, array $learned): array
    {
        $groups = config('importador.multi_source.'.$templateKey) ?? config('importador.multi_source.sub ubicacion', []);
        $result = [];

        // Si ya se aprendió una columna para este campo, se respeta.
        foreach ($available as $sourceKey => $_) {
            if (($learned[$sourceKey] ?? null) === $templateKey) {
                $result[] = $sourceKey;
            }
        }
        if ($result !== []) {
            return $result;
        }

        if (isset($available[$templateKey])) {
            return [$templateKey];
        }

        foreach ($groups as $words) {
            foreach ($available as $sourceKey => $_) {
                if (in_array($sourceKey, $result, true)) {
                    continue;
                }
                foreach ($words as $word) {
                    if ($sourceKey === $word || str_starts_with($sourceKey, $word.' ')) {
                        $result[] = $sourceKey;

                        continue 3;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Columnas del cliente que no se mapearon y vale la pena conservar en OBSERVACIONES.
     *
     * @param  array<string, string>  $sourceHeaders
     * @param  array<string, array{sources:list<string>}>  $mapping
     * @return list<string>
     */
    public function suggestExtras(array $sourceHeaders, array $mapping): array
    {
        $used = [];
        foreach ($mapping as $entry) {
            foreach ($entry['sources'] as $s) {
                $used[$s] = true;
            }
        }

        $extras = [];
        foreach ($sourceHeaders as $key => $_) {
            if (! isset($used[$key]) && ! $this->isNoise($key) && ! str_starts_with($key, 'columna ') && ! str_starts_with($key, 'obs ')) {
                $extras[] = $key;
            }
        }

        // Si hay demasiadas columnas sueltas, no se marca ninguna por defecto (el usuario elige).
        return count($extras) <= 8 ? $extras : [];
    }

    /**
     * @return SuggestedEntry
     */
    public function empty(): array
    {
        return ['mode' => 'none', 'sources' => [], 'fixed' => '', 'score' => 0.0, 'reason' => ''];
    }
}
