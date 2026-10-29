<?php

/*
|--------------------------------------------------------------------------
| Importador de activos CMMS
|--------------------------------------------------------------------------
|
| Diccionario de sinónimos usado por el mapeo inteligente de encabezados.
| La clave es el nombre "canónico" de una columna de la plantilla del CMMS
| (normalizado: minúsculas, sin acentos ni símbolos) y el valor es la lista
| de encabezados que suelen usar los clientes para esa misma información.
|
| Agregar sinónimos aquí mejora el mapeo sin tocar código. Además, el sistema
| aprende solo: cada mapeo confirmado por un usuario se guarda en la tabla
| `header_aliases` y tiene prioridad en los próximos análisis.
|
*/

return [

    'synonyms' => [
        'nombre' => [
            'nombre', 'nombre activo', 'nombre del activo', 'nombre equipo', 'nombre del equipo',
            'activo', 'equipo', 'descripcion', 'descripcion activo', 'descripcion equipo',
            'item', 'artefacto', 'denominacion',
        ],
        'codigo' => [
            'codigo', 'cod', 'codigo activo', 'codigo del activo', 'codigo equipo', 'tag',
            'n activo', 'no activo', 'nro activo', 'num activo', 'numero activo', 'numero de activo',
            'n de activo', 'id activo', 'n equipo', 'numero equipo', 'n serie activo', 'placa', 'inventario',
        ],
        'localizacion' => [
            'localizacion', 'ciudad', 'comuna', 'region', 'sede', 'localidad',
        ],
        'cliente' => [
            'cliente', 'empresa', 'mandante', 'razon social', 'proveedor', 'clientes proveedores',
        ],
        'sub ubicacion' => [
            'sub ubicacion', 'sububicacion', 'ubicacion', 'piso', 'nivel', 'sector', 'zona', 'area',
            'recinto', 'dependencia', 'lugar', 'ubicacion fisica',
        ],
        'estado final del equipo' => [
            'estado final del equipo', 'estado', 'estado equipo', 'estado del equipo', 'condicion', 'estado final',
        ],
        'tipo de activo' => [
            'tipo de activo', 'tipo activo', 'tipo', 'clase',
        ],
        'equipo' => [
            'equipo', 'tipo de equipo', 'tipo equipo', 'categoria', 'subcategoria',
        ],
        'marca' => ['marca', 'fabricante', 'brand', 'make'],
        'modelo' => ['modelo', 'model', 'mod'],
        'familia' => ['familia', 'sistema', 'especialidad', 'grupo', 'linea'],
        'prioridad' => ['prioridad', 'criticidad', 'importancia'],
        'observaciones' => [
            'observaciones', 'observacion', 'obs', 'comentarios', 'comentario', 'notas', 'nota', 'detalle', 'detalles',
        ],
        'n serie' => ['n serie', 'numero de serie', 'serie', 'serial', 'nro serie', 's n'],
        'capacidad' => ['capacidad', 'potencia', 'kva', 'kw'],
    ],

    /*
    | Columnas de la plantilla que pueden armarse combinando varias columnas
    | del cliente (por ejemplo Piso + Sector => Sub ubicación).
    */
    'multi_source' => [
        'sub ubicacion' => [
            ['piso', 'nivel', 'ubicacion', 'edificio'],
            ['sector', 'zona', 'area', 'recinto', 'sub ubicacion', 'dependencia'],
        ],
    ],

    /*
    | Puntaje mínimo (0-1) para proponer automáticamente una asociación.
    */
    'match_threshold' => 0.62,

    /*
    | Puntaje mínimo para aceptar un valor de catálogo "parecido" (con advertencia).
    */
    'catalog_threshold' => 0.72,

    /*
    | Desde este puntaje la coincidencia se considera segura y no genera advertencia
    | (ej. "6°" + "Común" => "6° PISO › COMÚN").
    */
    'catalog_confident' => 0.93,

    /*
    | Palabras que se ignoran al generar la abreviación del código de activo.
    */
    'code_stopwords' => ['n', 'no', 'nro', 'num', 'de', 'del', 'la', 'el', 'los', 'las', 'y', 'e', 'para', 'con', 'a', 'en', 'por', 'al', 'o'],

    /*
    | Cantidad máxima de filas que puede generar un rango tipo "SP6NM01 AL 28".
    */
    'max_range_expansion' => 1000,

    'max_upload_kb' => 20480,

    'disk' => 'local',
];
