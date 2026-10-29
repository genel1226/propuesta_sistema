<?php

namespace App\Services\Import\Processing;

/**
 * Expande códigos escritos como rango en una sola fila:
 *   "SP6NM01 AL 28"  => SP6NM01, SP6NM02, ..., SP6NM28
 *   "TAB-001 a 003"  => TAB-001, TAB-002, TAB-003
 */
class RangeExpander
{
    /**
     * @return list<string>|null null si el valor no es un rango.
     */
    public function expand(string $value): ?array
    {
        $value = trim($value);

        if (! preg_match('/^(.*?)(\d+)\s*(?:al|a|hasta)\s*(\d+)$/iu', $value, $m)) {
            return null;
        }

        [, $prefix, $start, $end] = $m;
        $from = (int) $start;
        $to = (int) $end;
        $max = (int) config('importador.max_range_expansion', 1000);

        if ($to <= $from || ($to - $from + 1) > $max || trim($prefix) === '') {
            return null;
        }

        $width = strlen($start);
        $codes = [];

        for ($n = $from; $n <= $to; $n++) {
            $codes[] = $prefix.str_pad((string) $n, $width, '0', STR_PAD_LEFT);
        }

        return $codes;
    }
}
