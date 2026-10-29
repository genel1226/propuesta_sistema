<?php

namespace App\Services\Import\Support;

use DateTimeInterface;
use Illuminate\Support\Str;

/**
 * Utilidades de texto para comparar encabezados y valores "sucios".
 */
final class Text
{
    /**
     * Normaliza un texto: minúsculas, sin acentos, sin símbolos y con espacios simples.
     * "N° Activo\n" => "n activo", "10º PISO" => "10 piso".
     */
    public static function normalize(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $text = self::toString($value);
        $text = str_replace(['°', 'º', 'ª'], ' ', $text);
        $text = Str::ascii($text);
        $text = mb_strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? '';

        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }

    /**
     * Convierte cualquier valor de celda en texto legible.
     */
    public static function toString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_bool($value)) {
            return $value ? 'Sí' : 'No';
        }

        if (is_float($value)) {
            // Evita "2403.0" o "1.0E+5" en valores enteros.
            return floor($value) === $value && abs($value) < 1e15
                ? (string) (int) $value
                : rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
        }

        return trim((string) $value);
    }

    public static function isBlank(mixed $value): bool
    {
        return self::toString($value) === '';
    }

    /**
     * Similitud entre 0 y 1 combinando coincidencia de palabras y similitud de caracteres.
     */
    public static function similarity(string $a, string $b): float
    {
        $a = self::normalize($a);
        $b = self::normalize($b);

        if ($a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            return 1.0;
        }

        $tokensA = array_values(array_unique(explode(' ', $a)));
        $tokensB = array_values(array_unique(explode(' ', $b)));

        $common = count(array_intersect($tokensA, $tokensB));
        $jaccard = $common / max(1, count(array_unique(array_merge($tokensA, $tokensB))));
        $containment = $common / max(1, min(count($tokensA), count($tokensB)));

        similar_text($a, $b, $percent);
        $chars = $percent / 100;

        $maxLen = max(strlen($a), strlen($b));
        $lev = $maxLen <= 255 ? 1 - (levenshtein($a, $b) / $maxLen) : $chars;

        return round(max(
            $jaccard,
            0.55 * $containment + 0.45 * $chars,
            0.5 * $chars + 0.5 * $lev,
        ), 4);
    }

    /**
     * Primera línea de un encabezado de plantilla: "NOMBRE\n(Campo Obligatorio)" => "NOMBRE".
     */
    public static function firstLine(string $text): string
    {
        $line = trim(strtok(str_replace("\r", '', $text), "\n") ?: '');

        return trim(preg_replace('/\(.*?\)/', '', $line) ?? $line);
    }
}
