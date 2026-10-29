<div class="mx-auto w-full max-w-6xl space-y-6">
    {{-- Encabezado y pasos --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">Importar activos</flux:heading>
            <flux:text class="mt-1">Sube el archivo del cliente y la plantilla del CMMS. El sistema mapea las columnas, llena la plantilla y valida cada fila.</flux:text>
        </div>

        <ol class="flex items-center gap-2 text-sm">
            @foreach (['upload' => 'Archivos', 'configure' => 'Mapeo', 'result' => 'Resultado'] as $key => $label)
                @php
                    $order = ['upload' => 1, 'configure' => 2, 'result' => 3];
                    $active = $step === $key;
                    $done = $order[$step] > $order[$key];
                @endphp
                <li class="flex items-center gap-2">
                    <span @class([
                        'flex size-7 items-center justify-center rounded-full text-xs font-semibold',
                        'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $active,
                        'bg-emerald-500 text-white' => $done,
                        'bg-zinc-200 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400' => ! $active && ! $done,
                    ])>{{ $done ? '✓' : $order[$key] }}</span>
                    <span @class(['font-medium', 'text-zinc-400' => ! $active && ! $done])>{{ $label }}</span>
                    @if (! $loop->last)
                        <span class="mx-1 h-px w-6 bg-zinc-300 dark:bg-zinc-600"></span>
                    @endif
                </li>
            @endforeach
        </ol>
    </div>

    {{-- PASO 1: subir archivos --}}
    @if ($step === 'upload')
        <form wire:submit="analyze" class="space-y-6">
            <div class="grid gap-6 md:grid-cols-2">
                @foreach ([
                    ['model' => 'sourceFile', 'title' => 'Archivo del cliente', 'hint' => 'Tal como lo envía el cliente: .xlsx, .xls o .csv. Puede tener títulos, varias hojas o celdas combinadas.', 'icon' => 'document-text'],
                    ['model' => 'templateFile', 'title' => 'Plantilla del CMMS', 'hint' => 'La plantilla de importación vacía (.xlsx) con su hoja de parámetros.', 'icon' => 'table-cells'],
                ] as $input)
                    @php $file = $this->{$input['model']}; @endphp
                    <label
                        for="{{ $input['model'] }}"
                        x-data="{ dragging: false }"
                        x-on:dragover.prevent="dragging = true"
                        x-on:dragleave.prevent="dragging = false"
                        x-on:drop.prevent="dragging = false; $refs.input.files = $event.dataTransfer.files; $refs.input.dispatchEvent(new Event('change'))"
                        :class="dragging ? 'border-zinc-900 bg-zinc-50 dark:border-white dark:bg-zinc-700/40' : ''"
                        class="group relative flex cursor-pointer flex-col items-center justify-center gap-3 rounded-2xl border-2 border-dashed border-zinc-300 p-8 text-center transition hover:border-zinc-500 dark:border-zinc-600"
                    >
                        <div class="flex size-12 items-center justify-center rounded-xl bg-zinc-100 dark:bg-zinc-700">
                            <flux:icon :name="$input['icon']" class="size-6 text-zinc-600 dark:text-zinc-300" />
                        </div>
                        <div>
                            <p class="font-semibold">{{ $input['title'] }}</p>
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ $input['hint'] }}</p>
                        </div>

                        @if ($file)
                            <span class="inline-flex max-w-full items-center gap-2 truncate rounded-full bg-emerald-50 px-3 py-1 text-sm font-medium text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">
                                <flux:icon.check-circle variant="mini" /> {{ $file->getClientOriginalName() }}
                            </span>
                        @else
                            <span class="text-sm font-medium text-zinc-700 underline underline-offset-4 dark:text-zinc-200">Elegir archivo o arrastrarlo aquí</span>
                        @endif

                        <div wire:loading wire:target="{{ $input['model'] }}" class="text-sm text-zinc-500">Subiendo…</div>

                        <input id="{{ $input['model'] }}" x-ref="input" type="file" wire:model="{{ $input['model'] }}" class="sr-only"
                               accept="{{ $input['model'] === 'templateFile' ? '.xlsx,.xlsm,.xls' : '.xlsx,.xls,.csv' }}">
                        @error($input['model'])
                            <p class="text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </label>
                @endforeach
            </div>

            @if ($analysisError)
                <flux:callout variant="danger" icon="x-circle" :heading="$analysisError" />
            @endif

            <div class="flex justify-end">
                <flux:button type="submit" variant="primary" icon="sparkles" wire:loading.attr="disabled" wire:target="analyze,sourceFile,templateFile">
                    <span wire:loading.remove wire:target="analyze">Analizar archivos</span>
                    <span wire:loading wire:target="analyze">Analizando…</span>
                </flux:button>
            </div>
        </form>
    @endif

    {{-- PASO 2: tablas + mapeo --}}
    @if ($step === 'configure' && $this->batch)
        @php
            $batch = $this->batch;
            $schema = $batch->template_schema;
            $headers = $this->sourceHeaders;
            $missing = $this->unmappedRequired();
        @endphp

        <div class="grid gap-4 md:grid-cols-3">
            <div class="rounded-2xl border border-zinc-200 p-4 dark:border-zinc-700">
                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Plantilla</p>
                <p class="mt-1 truncate font-semibold" title="{{ $batch->template_name }}">{{ $schema['category'] ?? $batch->template_name }}</p>
                <p class="text-sm text-zinc-500">{{ count($schema['columns']) }} columnas · {{ collect($schema['columns'])->where('required', true)->count() }} obligatorias</p>
            </div>
            <div class="rounded-2xl border border-zinc-200 p-4 dark:border-zinc-700">
                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Archivo del cliente</p>
                <p class="mt-1 truncate font-semibold" title="{{ $batch->source_name }}">{{ $batch->source_name }}</p>
                <p class="text-sm text-zinc-500">{{ count($batch->source_tables) }} tabla(s) detectada(s)</p>
            </div>
            <div class="rounded-2xl border border-zinc-200 p-4 dark:border-zinc-700">
                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Filas a procesar</p>
                <p class="mt-1 text-2xl font-semibold">{{ collect($batch->source_tables)->whereIn('id', $selectedTables)->sum('row_count') }}</p>
                <p class="text-sm text-zinc-500">antes de expandir rangos</p>
            </div>
        </div>

        {{-- Tablas detectadas --}}
        <section class="rounded-2xl border border-zinc-200 dark:border-zinc-700">
            <div class="border-b border-zinc-200 p-4 dark:border-zinc-700">
                <flux:heading size="lg">1. ¿Qué datos del cliente se importan?</flux:heading>
                <flux:text>Se detectaron estas tablas. Marca las que corresponden a la categoría de la plantilla.</flux:text>
            </div>
            <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($batch->source_tables as $table)
                    <label class="flex cursor-pointer items-start gap-3 p-4 hover:bg-zinc-50 dark:hover:bg-zinc-700/30">
                        <input type="checkbox" value="{{ $table['id'] }}" wire:model.live="selectedTables" class="mt-1 size-4 rounded border-zinc-300">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium">{{ $table['sheet'] }}</span>
                                @if ($table['title'])
                                    <span class="truncate text-sm text-zinc-500">{{ $table['title'] }}</span>
                                @endif
                                <flux:badge size="sm" color="zinc">{{ $table['row_count'] }} filas</flux:badge>
                                <flux:badge size="sm" color="zinc">encabezado en fila {{ $table['header_row'] }}</flux:badge>
                            </div>
                            <p class="mt-1 truncate text-sm text-zinc-500">{{ implode(' · ', $table['headers']) }}</p>
                        </div>
                    </label>
                @endforeach
            </div>
            @error('selectedTables') <p class="p-4 text-sm text-red-600">{{ $message }}</p> @enderror
        </section>

        {{-- Mapeo --}}
        <section class="rounded-2xl border border-zinc-200 dark:border-zinc-700">
            <div class="flex flex-col gap-2 border-b border-zinc-200 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-700">
                <div>
                    <flux:heading size="lg">2. Mapeo de columnas</flux:heading>
                    <flux:text>Columna de la plantilla ← columna(s) del cliente o un valor fijo para todas las filas.</flux:text>
                </div>
                <flux:button size="sm" icon="arrow-path" wire:click="resetSuggestion">Volver a sugerir</flux:button>
            </div>

            <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($this->columns as $column)
                    @php
                        $key = $column['key'];
                        $entry = $mapping[$key] ?? ['mode' => 'none', 'sources' => [], 'fixed' => ''];
                        $score = (float) ($entry['score'] ?? 0);
                        $isFixedColumn = $column['fixed'] !== null;
                    @endphp
                    <div wire:key="map-{{ $key }}" class="grid gap-3 p-4 lg:grid-cols-[minmax(0,15rem)_minmax(0,1fr)]">
                        <div>
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="font-medium">{{ $column['label'] }}</span>
                                <span class="text-xs text-zinc-400">col. {{ $column['letter'] }}</span>
                            </div>
                            <div class="mt-1 flex flex-wrap gap-1">
                                @if ($column['required']) <flux:badge size="sm" color="red">Obligatorio</flux:badge> @endif
                                @if ($column['unique']) <flux:badge size="sm" color="purple">Único</flux:badge> @endif
                                @if (count($column['catalog'])) <flux:badge size="sm" color="blue">Catálogo · {{ count($column['catalog']) }}</flux:badge> @endif
                                @if (count($column['options'])) <flux:badge size="sm" color="blue">Opciones: {{ implode(', ', $column['options']) }}</flux:badge> @endif
                            </div>
                        </div>

                        <div class="min-w-0 space-y-2">
                            @if ($isFixedColumn)
                                <p class="text-sm text-zinc-500">Valor fijo de la plantilla <code class="rounded bg-zinc-100 px-1.5 py-0.5 dark:bg-zinc-700">{{ $column['fixed'] }}</code> — se copia en todas las filas.</p>
                            @else
                                <div class="flex flex-wrap items-center gap-2">
                                    <div class="inline-flex rounded-lg bg-zinc-100 p-0.5 text-sm dark:bg-zinc-700">
                                        @foreach (['source' => 'Columna del cliente', 'fixed' => 'Valor fijo', 'none' => 'Vacío'] as $mode => $modeLabel)
                                            <button type="button" wire:click="setMode('{{ $key }}', '{{ $mode }}')" @class([
                                                'rounded-md px-2.5 py-1 font-medium transition',
                                                'bg-white shadow-sm dark:bg-zinc-900' => $entry['mode'] === $mode,
                                                'text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' => $entry['mode'] !== $mode,
                                            ])>{{ $modeLabel }}</button>
                                        @endforeach
                                    </div>

                                    @if ($entry['mode'] !== 'none' && ($entry['reason'] ?? '') !== '')
                                        @php
                                            $color = $score >= 0.9 ? 'emerald' : ($score >= 0.75 ? 'amber' : 'zinc');
                                            $text = $score >= 0.9 ? 'Alta' : ($score >= 0.75 ? 'Media' : 'Revisar');
                                        @endphp
                                        <span class="text-xs text-zinc-500">
                                            <flux:badge size="sm" :color="$color">{{ $text }}</flux:badge>
                                            {{ $entry['reason'] }}
                                        </span>
                                    @endif
                                </div>

                                @if ($entry['mode'] === 'source')
                                    <div class="flex flex-wrap items-center gap-2">
                                        @foreach ($entry['sources'] as $i => $source)
                                            <div class="flex items-center gap-1" wire:key="src-{{ $key }}-{{ $i }}">
                                                @if ($i > 0) <span class="text-zinc-400">+</span> @endif
                                                <select wire:model.live="mapping.{{ $key }}.sources.{{ $i }}"
                                                        class="rounded-lg border border-zinc-300 bg-white py-1.5 pl-2 pr-8 text-sm dark:border-zinc-600 dark:bg-zinc-800">
                                                    <option value="">— Elegir columna —</option>
                                                    @foreach ($headers as $hKey => $hLabel)
                                                        <option value="{{ $hKey }}">{{ $hLabel }}</option>
                                                    @endforeach
                                                </select>
                                                <button type="button" wire:click="removeSource('{{ $key }}', {{ $i }})" class="text-zinc-400 hover:text-red-600" title="Quitar">
                                                    <flux:icon.x-mark variant="micro" />
                                                </button>
                                            </div>
                                        @endforeach
                                        <button type="button" wire:click="addSource('{{ $key }}')" class="text-sm font-medium text-zinc-600 hover:underline dark:text-zinc-300">+ combinar otra columna</button>
                                    </div>
                                    @php $preview = $this->preview(array_filter($entry['sources'])); @endphp
                                    @if ($preview !== '')
                                        <p class="truncate text-xs text-zinc-500">Ej.: {{ $preview }}</p>
                                    @endif
                                @elseif ($entry['mode'] === 'fixed')
                                    @if (count($column['catalog']))
                                        <select wire:model.live="mapping.{{ $key }}.fixed"
                                                class="w-full max-w-xl rounded-lg border border-zinc-300 bg-white py-1.5 pl-2 pr-8 text-sm dark:border-zinc-600 dark:bg-zinc-800">
                                            <option value="">— Elegir un valor del catálogo —</option>
                                            @foreach ($column['catalog'] as $option)
                                                <option value="{{ $option['value'] }}">{{ isset($option['sub']) ? $option['cliente'].' › '.$option['ubicacion'].' › '.$option['sub'] : $option['label'] }}</option>
                                            @endforeach
                                        </select>
                                    @elseif (count($column['options']))
                                        <select wire:model.live="mapping.{{ $key }}.fixed" class="rounded-lg border border-zinc-300 bg-white py-1.5 pl-2 pr-8 text-sm dark:border-zinc-600 dark:bg-zinc-800">
                                            <option value="">— Elegir —</option>
                                            @foreach ($column['options'] as $option)
                                                <option value="{{ $option }}">{{ $option }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <input type="text" wire:model.blur="mapping.{{ $key }}.fixed" placeholder="Valor para todas las filas"
                                               class="w-full max-w-xl rounded-lg border border-zinc-300 bg-white px-2 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800">
                                    @endif
                                @else
                                    <p class="text-sm text-zinc-500">
                                        @if ($column['role'] === 'codigo' && $codeMode !== 'keep')
                                            Se generará automáticamente desde el nombre (ej. «Vitrina de frio» → VF-01).
                                        @else
                                            Quedará vacío.
                                        @endif
                                    </p>
                                @endif
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Opciones --}}
        <section class="grid gap-6 rounded-2xl border border-zinc-200 p-4 md:grid-cols-2 dark:border-zinc-700">
            <div class="space-y-4">
                <flux:heading size="lg">3. Opciones</flux:heading>

                @if (collect($this->columns)->firstWhere('role', 'codigo'))
                    <div>
                        <p class="text-sm font-medium">Código de activo</p>
                        <div class="mt-2 space-y-1.5 text-sm">
                            <label class="flex items-start gap-2"><input type="radio" wire:model.live="codeMode" value="fill" class="mt-0.5"> <span>Usar el código del cliente y <b>generar los que falten</b> (abreviación del nombre + correlativo: VF-01).</span></label>
                            <label class="flex items-start gap-2"><input type="radio" wire:model.live="codeMode" value="generate_all" class="mt-0.5"> <span>Generar <b>todos</b> los códigos, ignorando los del cliente.</span></label>
                            <label class="flex items-start gap-2"><input type="radio" wire:model.live="codeMode" value="keep" class="mt-0.5"> <span>Sólo usar los códigos del cliente (los vacíos se marcan como error).</span></label>
                        </div>
                    </div>
                @endif

                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" wire:model.live="expandRanges" class="mt-0.5 rounded border-zinc-300">
                    <span><b>Expandir rangos</b>: «SP6NM01 AL 28» se convierte en 28 activos (SP6NM01 … SP6NM28).</span>
                </label>
            </div>

            <div>
                <p class="text-sm font-medium">Columnas del cliente que se agregan a OBSERVACIONES</p>
                <p class="text-xs text-zinc-500">Datos útiles que no tienen columna propia en la plantilla (capacidad, potencia, etc.).</p>
                @php $free = $this->extraCandidates(); @endphp
                <div class="mt-2 flex flex-wrap gap-2">
                    @forelse ($free as $hKey => $hLabel)
                        <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-zinc-300 px-2.5 py-1 text-sm has-[:checked]:border-zinc-900 has-[:checked]:bg-zinc-900 has-[:checked]:text-white dark:border-zinc-600 dark:has-[:checked]:border-white dark:has-[:checked]:bg-white dark:has-[:checked]:text-zinc-900">
                            <input type="checkbox" value="{{ $hKey }}" wire:model.live="extras" class="sr-only">
                            {{ $hLabel }}
                        </label>
                    @empty
                        <p class="text-sm text-zinc-500">Todas las columnas del cliente ya están mapeadas.</p>
                    @endforelse
                </div>
            </div>
        </section>

        @if ($missing)
            <flux:callout variant="warning" icon="exclamation-triangle" heading="Columnas obligatorias sin mapear: {{ implode(', ', $missing) }}">
                <flux:callout.text>Puedes procesar igual: esas filas quedarán marcadas con error en el archivo de salida.</flux:callout.text>
            </flux:callout>
        @endif

        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-between">
            <flux:button variant="ghost" icon="arrow-path" wire:click="startOver">Empezar de nuevo</flux:button>
            <flux:button variant="primary" icon="bolt" wire:click="process" wire:loading.attr="disabled" wire:target="process">
                Llenar plantilla y validar
            </flux:button>
        </div>
    @endif
</div>
