<?php

namespace App\Services\Import\Mapping;

use App\Services\Import\Support\Text;
use App\Services\Import\Template\CatalogOption;

/**
 * Busca el valor de catálogo que corresponde a un texto escrito por el cliente.
 * Ej.: "6°" + "Norte"  =>  "[Cliente] Edificio de Servicio - [Ubicación] 6° PISO - [Sub ubicación] NORTE |0A9EEER29"
 */
class CatalogMatcher
{
    /** @var array<string, array{0:?CatalogOption,1:float}> */
    protected array $cache = [];

    /**
     * @param  list<CatalogOption>  $options
     * @return array{option: ?CatalogOption, score: float, exact: bool}
     */
    public function match(string $value, array $options, ?string $cliente = null): array
    {
        $normalized = Text::normalize($value);

        if ($normalized === '' || $options === []) {
            return ['option' => null, 'score' => 0.0, 'exact' => false];
        }

        $cacheKey = md5($normalized.'|'.$cliente.'|'.count($options).'|'.$options[0]->value);
        if (isset($this->cache[$cacheKey])) {
            [$option, $score] = $this->cache[$cacheKey];

            return ['option' => $option, 'score' => $score, 'exact' => $score >= 1.0];
        }

        $best = null;
        $bestScore = 0.0;
        $valueNumbers = $this->numbers($normalized);
        $valueTokens = $this->stems($normalized);

        foreach ($options as $option) {
            if (! $option->belongsToCliente($cliente)) {
                continue;
            }

            // Coincidencia exacta con el valor completo, la etiqueta o el ID.
            foreach ([$option->value, $option->label, $option->matchText(), (string) $option->id] as $candidate) {
                if ($candidate !== '' && Text::normalize($candidate) === $normalized) {
                    $this->cache[$cacheKey] = [$option, 1.0];

                    return ['option' => $option, 'score' => 1.0, 'exact' => true];
                }
            }

            $target = Text::normalize($option->matchText());

            // Si el valor tiene números (pisos), la opción debe tener exactamente los mismos.
            if ($valueNumbers !== [] && $this->numbers($target) !== $valueNumbers) {
                continue;
            }

            $score = Text::similarity($normalized, $target);

            // Bonus: todas las palabras del cliente aparecen en la opción (singular/plural da igual).
            $targetTokens = $this->stems($target);
            if ($valueTokens !== [] && array_diff($valueTokens, $targetTokens) === []) {
                $score = max($score, 0.85 + 0.1 * (count($valueTokens) / max(1, count($targetTokens))));
            }

            // Sub ubicaciones del tipo "6° PISO › 6° PISO": preferir la más específica cuando hay sector.
            if ($option->subUbicacion !== null && Text::normalize($option->ubicacion) === Text::normalize($option->subUbicacion)) {
                $score -= 0.02;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $option;
            }
        }

        if ($bestScore < (float) config('importador.catalog_threshold', 0.72)) {
            $best = null;
        }

        $this->cache[$cacheKey] = [$best, round($bestScore, 4)];

        return ['option' => $best, 'score' => round($bestScore, 4), 'exact' => false];
    }

    /**
     * Busca la opción (de cualquier cliente) para explicar errores del tipo
     * "la sub ubicación existe pero pertenece a otro cliente".
     *
     * @param  list<CatalogOption>  $options
     */
    public function matchAnyCliente(string $value, array $options): ?CatalogOption
    {
        return $this->match($value, $options)['option'];
    }

    /**
     * @return list<string>
     */
    protected function numbers(string $normalized): array
    {
        preg_match_all('/\d+/', $normalized, $m);
        $numbers = array_map(fn ($n) => ltrim($n, '0') ?: '0', $m[0]);
        sort($numbers);

        return array_values(array_unique($numbers));
    }

    /**
     * Palabras sin plural simple ("servicios" => "servicio") y sin palabras vacías.
     *
     * @return list<string>
     */
    protected function stems(string $normalized): array
    {
        $stop = ['de', 'del', 'la', 'el', 'los', 'las', 'y', 'piso', 'nivel'];
        $tokens = [];

        foreach (explode(' ', $normalized) as $token) {
            if ($token === '' || in_array($token, $stop, true)) {
                continue;
            }
            $tokens[] = strlen($token) > 4 ? preg_replace('/(es|s)$/', '', $token) : $token;
        }

        return array_values(array_unique($tokens));
    }
}
