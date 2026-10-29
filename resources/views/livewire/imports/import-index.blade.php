<div class="mx-auto w-full max-w-6xl space-y-6">
    <div class="flex items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Historial de importaciones</flux:heading>
            <flux:text>Todas las plantillas que has llenado y validado.</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" :href="route('imports.create')" wire:navigate>Nueva importación</flux:button>
    </div>

    <div class="overflow-x-auto rounded-2xl border border-zinc-200 dark:border-zinc-700">
        <table class="min-w-full text-sm">
            <thead class="bg-zinc-50 text-left text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800">
                <tr>
                    <th class="px-4 py-2">#</th>
                    <th class="px-4 py-2">Categoría</th>
                    <th class="px-4 py-2">Archivo del cliente</th>
                    <th class="px-4 py-2">Filas</th>
                    <th class="px-4 py-2">Resultado</th>
                    <th class="px-4 py-2">Fecha</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($batches as $batch)
                    <tr wire:key="b-{{ $batch->id }}" class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                        onclick="Livewire.navigate('{{ $batch->status === 'draft' ? route('imports.edit', $batch) : route('imports.show', $batch) }}')">
                        <td class="px-4 py-3 font-mono text-zinc-500">{{ $batch->id }}</td>
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $batch->category ?? $batch->template_name }}</p>
                            @if ($batch->cliente) <p class="text-xs text-zinc-500">{{ $batch->cliente }}</p> @endif
                        </td>
                        <td class="max-w-xs truncate px-4 py-3 text-zinc-500">{{ $batch->source_name }}</td>
                        <td class="px-4 py-3">{{ $batch->stats['total'] ?? '—' }}</td>
                        <td class="px-4 py-3">
                            @switch($batch->status)
                                @case('completed')
                                    <div class="flex flex-wrap gap-1">
                                        <flux:badge size="sm" color="green">{{ $batch->stats['ok'] ?? 0 }} OK</flux:badge>
                                        @if ($batch->stats['warning'] ?? 0) <flux:badge size="sm" color="amber">{{ $batch->stats['warning'] }} adv.</flux:badge> @endif
                                        @if ($batch->stats['error'] ?? 0) <flux:badge size="sm" color="red">{{ $batch->stats['error'] }} errores</flux:badge> @endif
                                    </div>
                                    @break
                                @case('failed') <flux:badge size="sm" color="red">Falló</flux:badge> @break
                                @case('processing') <flux:badge size="sm" color="blue">Procesando</flux:badge> @break
                                @default <flux:badge size="sm" color="zinc">Borrador</flux:badge>
                            @endswitch
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-zinc-500">{{ $batch->created_at->format('d/m/Y H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-zinc-500">Aún no hay importaciones.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $batches->links() }}
</div>
