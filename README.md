# Importador de activos CMMS

Sistema web (Laravel 13 + Livewire 4 + Tailwind 4 + Flux) que toma el **archivo desordenado del cliente**
y la **plantilla oficial de importación del CMMS**, mapea las columnas automáticamente, llena la plantilla
y valida fila por fila.

## Flujo

1. **Archivos**: se sube el archivo del cliente (`.xlsx`, `.xls`, `.csv`) y la plantilla vacía del CMMS (`.xlsx`).
2. **Mapeo**: el sistema detecta las tablas del archivo del cliente y propone qué columna alimenta cada columna de la plantilla. El usuario puede ajustarlo.
3. **Procesamiento**: llena la plantilla, genera códigos, expande rangos y valida.
4. **Resultado**: resumen en pantalla y dos descargas:
   - **Con validación**: todas las filas, más la columna `RESULTADO VALIDACIÓN` (OK / ADVERTENCIA / ERROR) y una hoja `RESUMEN VALIDACIÓN`.
   - **Lista para importar**: sólo las filas sin errores y sin columnas extra, lista para subir al CMMS.

## Qué entiende el sistema

**De la plantilla** (no hay columnas fijas en el código, porque cada plantilla se personaliza por cliente):

| En el encabezado / archivo                                   | Regla que aplica                          |
|--------------------------------------------------------------|-------------------------------------------|
| `(Campo Obligatorio)`                                        | Error si queda vacío                      |
| `(Campo único)`                                              | Error si el valor se repite en el archivo |
| `Ingrese una de las siguientes opciones: …`                  | Sólo acepta esas opciones                 |
| Listas desplegables → hoja `PARÁMETROS (No tocar)`           | Catálogo: se escribe `Etiqueta|ID`        |
| `[Cliente] X - [Ubicación] Y - [Sub ubicación] Z`            | La sub ubicación debe ser del cliente     |
| `(No modifique el valor.)` con valor en la 1ª fila           | Se copia en todas las filas (categoría)   |

**Del archivo del cliente**:

- Títulos y leyendas antes del encabezado.
- Encabezados en dos filas (`N°` + `Activo`).
- Celdas combinadas verticalmente (por ejemplo, el piso en varias filas).
- Varias hojas y varias tablas en una misma hoja.
- Filas de totales y fórmulas.
- Rangos como `SP6NM01 AL 28`, que se convierten en 28 activos.

**Mapeo inteligente** (`app/Services/Import/Mapping/HeaderMatcher.php`):

1. Mismo nombre.
2. Correcciones manuales aprendidas de importaciones anteriores (tabla `header_aliases`).
3. Diccionario de sinónimos, en `config/importador.php`. Se pueden agregar sinónimos sin tocar código.
4. Similitud de texto.

La sub ubicación se arma combinando *Piso/Ubicación* + *Sector/Zona*. El cliente y la localización se
deducen del nombre del archivo y de los títulos. El estado del equipo usa "Operativo" por defecto.

**Código de activo**: si viene en el archivo se usa. Si falta, se genera con la abreviación del nombre más un correlativo
desde 01 (`Vitrina de frio` → `VF-01`, `Abatidor` → `ABA-01`), sin repetir códigos existentes.

## Requisitos

- PHP **8.4**, con las extensiones `zip`, `gd`, `mbstring`, `xml`, `fileinfo`, `pdo_mysql` e `intl`.
- Composer 2.
- Node 22 o superior y npm.
- MySQL 8 o MariaDB 10.6 o superior.

## Instalación local

```bash
git clone -b feature/importador-activos https://github.com/genel1226/propuesta_sistema.git
cd propuesta_sistema

composer install
npm install

cp .env.example .env            # Windows: copy .env.example .env
php artisan key:generate
```

Crea la base de datos en MySQL y ajusta `.env` si tu usuario o clave son distintos:

```sql
CREATE DATABASE propuesta_sistema CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```bash
php artisan migrate --seed      # crea las tablas y el usuario inicial
npm run build                   # o "npm run dev" mientras desarrollas
composer run dev                # servidor + cola + vite + logs
```

Abre <http://localhost:8000> e ingresa con:

- **Usuario:** `admin@cmmshere.com`
- **Contraseña:** `password`

Cámbiala en *Configuración* después del primer ingreso.

> **Cola de trabajos**: el procesamiento corre en un *job*. `composer run dev` ya levanta el worker.
> Si levantas los procesos por separado, ejecuta también `php artisan queue:work`.
> Para pruebas rápidas sin worker, usa `QUEUE_CONNECTION=sync` en `.env`.

## Pruebas

```bash
composer test          # pint + phpstan (nivel 7) + pest
```

Las pruebas de integración usan archivos reales de clientes que **no se suben al repositorio**.
`tests/Fixtures/README.md` explica cómo agregarlos. Sin esos archivos, esas pruebas se omiten.

## Estructura

```
app/Services/Import/
├── Template/TemplateParser.php     # lee reglas, catálogos y valores fijos de la plantilla
├── Source/SourceReader.php         # convierte el Excel del cliente en tablas limpias
├── Mapping/HeaderMatcher.php       # mapeo inteligente de encabezados
├── Mapping/CatalogMatcher.php      # busca valores en los catálogos (con tolerancia)
├── Processing/ImportProcessor.php  # llena y valida fila por fila
├── Processing/CodeGenerator.php    # VF-01, VF-02…
├── Processing/RangeExpander.php    # "SP6NM01 AL 28" → 28 filas
├── Export/TemplateFiller.php       # escribe sobre la plantilla original (conserva listas y parámetros)
└── ImportService.php               # orquesta el flujo
app/Livewire/Imports/               # ImportWizard (subir + mapear), ImportShow (resultado), ImportIndex (historial)
app/Jobs/ProcessImportBatch.php     # procesamiento en segundo plano
config/importador.php               # sinónimos, umbrales y opciones
```
