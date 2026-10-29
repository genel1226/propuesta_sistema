<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $source_header
 * @property string $template_key
 * @property int $hits
 */
#[Fillable(['source_header', 'template_key', 'hits'])]
class HeaderAlias extends Model
{
    /**
     * Encabezado normalizado del cliente => clave de plantilla más usada.
     *
     * @return array<string, string>
     */
    public static function learnedMap(): array
    {
        $map = [];

        foreach (static::query()->orderBy('hits')->get(['source_header', 'template_key']) as $alias) {
            $map[$alias->source_header] = $alias->template_key;
        }

        return $map;
    }

    public static function learn(string $sourceHeader, string $templateKey): void
    {
        $alias = static::query()->firstOrNew(['source_header' => $sourceHeader, 'template_key' => $templateKey]);
        $alias->hits = $alias->exists ? $alias->hits + 1 : 1;
        $alias->save();
    }
}
