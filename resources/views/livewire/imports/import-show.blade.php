@php
    $batch = $this->batch;
    $stats = $batch->stats ?? [];
@endphp

<div class="mx-auto w-full max-w-6xl space-y-6" @if (! $batch->isFinished()) wire:poll.2s="refreshStatus" @endif>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <a href="{{ route('imports.index') }}" wire:navigate class="text-sm text-zinc-500 hover:underline">← Historial</a>
            <flux:heading size="xl" level="1" class="mt-1">Importación #{{ $batch->id }} · {{ $batch->category ?? $batch->template_name }}</flux:heading>
            <flux:text class="truncate">{{ $batch->source_name }} → {{ $batch->template_name }} @if ($batch->cliente) · {{ $batch->cliente }} @endif</flux:text>
        </div>

        @if ($batch->status === 'completed')
            <div class="flex flex-wrap gap-2">
                <flux:button :href="route('imports.edit', $batch)" wire:navigate icon="cog-6-tooth">Ajustar mapeo</flux:button>
                <flux:button :href="route('imports.download', [$batch, 'clean'])" icon="document-check">Lista para importar</flux:button>
                <flux:button :href="route('imports.download', [$batch, 'result'])" variant="primary" icon="arrow-down-tray">Descargar con validación</flux:button>
            </div>
        @endif
    </div>

    @if (in_array($batch->status, ['processing', 'draft']))
        <div class="flex items-center gap-4 rounded-2xl border border-zinc-200 p-6 dark:border-zinc-700">
            <flux:icon.arrow-path class="size-6 animate-spin text-zinc-500" />
            <div>
                <p class="font-semibold">Procesando…</p>
                <p class="text-sm text-zinc-500">Mapeando columnas, llenando la plantilla y validando cada fila. Esta página se actualiza sola.</p>
                <p class="mt-1 text-xs text-zinc-400">Si tarda demasiado, verifica que el worker de colas esté corriendo (<code>php artisan queue:work</code>).</p>
            </div>
        </div>
    @elseif ($batch->status === 'failed')
        <flux:callout variant="danger" icon="x-circle" heading="La importación falló">
            <flux:callout.text>{{ $batch->error }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button size="sm" :href="route('imports.edit', $batch)" wire:navigate>Revisar mapeo</flux:button>
            </x-slot>
        </flux:callout>
    @else
        {{-- Tarjetas de resumen --}}
        @php
            $total = max(1, $stats['total'] ?? 0);
            $cards = [
                ['label' => 'Filas en la plantilla', 'value' => $stats['total'] ?? 0, 'class' => 'text-zinc-900 dark:text-white', 'filter' => 'all'],
                ['label' => 'Sin observaciones', 'value' => $stats['ok'] ?? 0, 'class' => 'text-emerald-600', 'filter' => 'ok'],
                ['label' => 'Con advertencias', 'value' => $stats['warning'] ?? 0, 'class' => 'text-amber-600', 'filter' => 'warning'],
                ['label' => 'Con errores', 'value' => $stats['error'] ?? 0, 'class' => 'text-red-600', 'filter' => 'error'],
            ];
        @endphp
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            @foreach ($cards as $card)
                <button type="button" wire:click="$set('filter', '{{ $card['filter'] }}')" @class([
                    'rounded-2xl border p-4 text-left transition hover:border-zinc-400',
                    'border-zinc-900 ring-1 ring-zinc-900 dark:border-white dark:ring-white' => $filter === $card['filter'],
                    'border-zinc-200 dark:border-zinc-700' => $filter !== $card['filter'],
                ])>
                    <p class="text-sm text-zinc-500">{{ $card['label'] }}</p>
                    <p class="mt-1 text-3xl font-semibold {{ $card['class'] }}">{{ number_format($card['value']) }}</p>
                    @if ($card['filter'] !== 'all')
                        <p class="text-xs text-zinc-400">{{ round($card['value'] * 100 / $total) }}%</p>
                    @endif
                </button>
            @endforeach
        </div>

        {{-- Barra de proporción --}}
        <div class="flex h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-700">
            <div class="bg-emerald-500" style="width: {{ ($stats['ok'] ?? 0) * 100 / $total }}%"></div>
            <div class="bg-amber-400" style="width: {{ ($stats['warning'] ?? 0) * 100 / $total }}%"></div>
            <div class="bg-red-500" style="width: {{ ($stats['error'] ?? 0) * 100 / $total }}%"></div>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="rounded-2xl border border-zinc-200 p-4 lg:col-span-2 dark:border-zinc-700">
                <flux:heading>Observaciones más frecuentes</flux:heading>
                <ul class="mt-3 space-y-2 text-sm">
                    @forelse (array_slice($stats['by_message'] ?? [], 0, 8, true) as $group => $count)
                        @php [$level, $column, $type] = explode('|', $group, 3); @endphp
                        <li class="flex items-center justify-between gap-3">
                            <span class="flex min-w-0 items-center gap-2">
                                <span @class(['size-2 shrink-0 rounded-full', 'bg-red-500' => $level === 'error', 'bg-amber-400' => $level === 'warning'])></span>
                                <span class="truncate"><b>{{ $column }}</b> · {{ $type }}</span>
                            </span>
                            <span class="font-mono text-zinc-500">{{ $count }}</span>
                        </li>
                    @empty
                        <li class="text-zinc-500">Ninguna. Todas las filas pasaron la validación.</li>
                    @endforelse
                </ul>
            </div>
            <div class="space-y-2 rounded-2xl border border-zinc-200 p-4 text-sm dark:border-zinc-700">
                <flux:heading>Automatizaciones</flux:heading>
                <p class="flex justify-between"><span class="text-zinc-500">Códigos generados</span><b>{{ $stats['generated_codes'] ?? 0 }}</b></p>
                <p class="flex justify-between"><span class="text-zinc-500">Filas creadas desde rangos</span><b>{{ $stats['expanded_rows'] ?? 0 }}</b></p>
                <p class="flex justify-between"><span class="text-zinc-500">Procesado</span><b>{{ $batch->processed_at?->format('d/m/Y H:i') }}</b></p>
                @foreach ($stats['notes'] ?? [] as $note)
                    <p class="rounded-lg bg-amber-50 p-2 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">{{ $note }}</p>
                @endforeach
            </div>
        </div>

        {{-- Detalle por fila --}}
        <section class="rounded-2xl border border-zinc-200 dark:border-zinc-700">
            <div class="flex flex-col gap-3 border-b border-zinc-200 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-700">
                <div class="inline-flex rounded-lg bg-zinc-100 p-0.5 text-sm dark:bg-zinc-700">
                    @foreach (['all' => 'Todas', 'error' => 'Errores', 'warning' => 'Advertencias', 'ok' => 'OK'] as $value => $label)
                        <button type="button" wire:click="$set('filter', '{{ $value }}')" @class([
                            'rounded-md px-3 py-1 font-medium',
                            'bg-white shadow-sm dark:bg-zinc-900' => $filter === $value,
                            'text-zinc-500' => $filter !== $value,
                        ])>{{ $label }}</button>
                    @endforeach
                </div>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Buscar nombre, código, mensaje…"
                       class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm sm:w-72 dark:border-zinc-600 dark:bg-zinc-800">
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-zinc-50 text-left text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800">
                        <tr>
                            <th class="px-4 py-2">Fila</th>
                            <th class="px-4 py-2">Activo</th>
                            <th class="px-4 py-2">Origen</th>
                            <th class="px-4 py-2">Validación</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($pageRows as $row)
                            <tr wire:key="row-{{ $row['excel_row'] }}" class="align-top">
                                <td class="whitespace-nowrap px-4 py-3 font-mono text-zinc-500">{{ $row['excel_row'] }}</td>
                                <td class="px-4 py-3">
                                    <p class="font-medium">{{ $row['name'] ?: '—' }}</p>
                                    @if ($row['code']) <p class="font-mono text-xs text-zinc-500">{{ $row['code'] }}</p> @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-zinc-500">{{ $row['origin'] }}</td>
                                <td class="px-4 py-3">
                                    @if ($row['issues'] === [])
                                        <flux:badge size="sm" color="green">OK</flux:badge>
                                    @else
                                        <ul class="space-y-1">
                                            @foreach ($row['issues'] as $issue)
                                                <li class="flex gap-2">
                                                    <flux:badge size="sm" :color="match($issue['level']) { 'error' => 'red', 'warning' => 'amber', default => 'zinc' }">
                                                        {{ match($issue['level']) { 'error' => 'Error', 'warning' => 'Advertencia', default => 'Info' } }}
                                                    </flux:badge>
                                                    <span>@if ($issue['column'])<b>{{ $issue['column'] }}:</b>@endif {{ $issue['message'] }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-8 text-center text-zinc-500">No hay filas para este filtro.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @php $count = count($this->filteredRows); @endphp
            <div class="flex items-center justify-between border-t border-zinc-200 p-4 text-sm dark:border-zinc-700">
                <span class="text-zinc-500">
                    {{ $count ? (($page - 1) * $perPage + 1).'–'.min($page * $perPage, $count).' de '.$count : '0' }} filas
                </span>
                <div class="flex gap-2">
                    <flux:button size="sm" wire:click="previousPage" :disabled="$page <= 1">Anterior</flux:button>
                    <flux:button size="sm" wire:click="nextPage" :disabled="$page * $perPage >= $count">Siguiente</flux:button>
                </div>
            </div>
        </section>
    @endif
</div>
