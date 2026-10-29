<?php

namespace App\Services\Import\Processing;

use Illuminate\Support\Str;

/**
 * Genera códigos de activo: abreviación del nombre + guion + correlativo desde 01.
 *   "Vitrina de frio"     => VF-01, VF-02, ...
 *   "Abatidor"            => ABA-01
 *   "Unidad Condensadora" => UC-01
 * Nunca repite un código que ya exista en el archivo.
 */
class CodeGenerator
{
    /** @var array<string, int> */
    protected array $counters = [];

    /** @var array<string, true> */
    protected array $taken = [];

    /**
     * @param  iterable<string>  $existingCodes  Códigos que ya vienen en el archivo.
     */
    public function __construct(iterable $existingCodes = [])
    {
        foreach ($existingCodes as $code) {
            $this->reserve($code);
        }
    }

    public function reserve(string $code): void
    {
        $code = mb_strtoupper(trim($code));
        if ($code !== '') {
            $this->taken[$code] = true;
        }
    }

    public function generate(string $name): string
    {
        $prefix = $this->prefix($name);
        $number = $this->counters[$prefix] ?? 0;

        do {
            $number++;
            $code = $prefix.'-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT);
        } while (isset($this->taken[$code]));

        $this->counters[$prefix] = $number;
        $this->taken[$code] = true;

        return $code;
    }

    public function prefix(string $name): string
    {
        $clean = strtoupper(Str::ascii($name));
        $clean = preg_replace('/[^A-Z0-9 ]+/', ' ', $clean) ?? '';
        $stop = array_map('strtoupper', config('importador.code_stopwords', []));

        $words = array_values(array_filter(
            explode(' ', $clean),
            fn ($w) => $w !== '' && ! in_array($w, $stop, true) && ! ctype_digit($w)
        ));

        if ($words === []) {
            return 'ACT';
        }

        if (count($words) === 1) {
            return substr($words[0], 0, 3);
        }

        return implode('', array_map(fn ($w) => $w[0], array_slice($words, 0, 4)));
    }
}
