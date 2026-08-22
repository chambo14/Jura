@props(['kpi'])

@php
    $tones = [
        'zinc' => 'text-zinc-600 dark:text-zinc-300 bg-zinc-100 dark:bg-zinc-800',
        'blue' => 'text-blue-600 dark:text-blue-300 bg-blue-100 dark:bg-blue-950',
        'green' => 'text-green-600 dark:text-green-300 bg-green-100 dark:bg-green-950',
        'amber' => 'text-amber-600 dark:text-amber-300 bg-amber-100 dark:bg-amber-950',
        'red' => 'text-red-600 dark:text-red-300 bg-red-100 dark:bg-red-950',
        'violet' => 'text-violet-600 dark:text-violet-300 bg-violet-100 dark:bg-violet-950',
    ];

    $tendances = [
        'green' => 'text-green-600 dark:text-green-400',
        'red' => 'text-red-600 dark:text-red-400',
        'zinc' => 'text-zinc-500 dark:text-zinc-400',
    ];
@endphp

<div {{ $attributes->class('flex items-start gap-4 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900') }}>
    <div class="flex size-10 shrink-0 items-center justify-center rounded-lg {{ $tones[$kpi->couleur] ?? $tones['zinc'] }}">
        <flux:icon :name="$kpi->icone" variant="mini" />
    </div>

    <div class="min-w-0 flex-1">
        <div class="truncate text-sm text-zinc-500 dark:text-zinc-400">{{ $kpi->libelle }}</div>

        <div class="mt-0.5 flex items-baseline gap-2">
            <span class="whitespace-nowrap text-2xl font-semibold tabular-nums text-zinc-900 dark:text-white">
                {{ $kpi->valeur }}
            </span>

            @if ($kpi->tendanceFormatee() !== null)
                <span
                    class="flex shrink-0 items-center gap-0.5 whitespace-nowrap text-xs font-medium tabular-nums {{ $tendances[$kpi->couleurTendance()] }}"
                    title="Évolution par rapport à la semaine précédente"
                >
                    <flux:icon :name="$kpi->iconeTendance()" variant="micro" class="size-3.5" />
                    {{ $kpi->tendanceFormatee() }}
                </span>
            @endif
        </div>

        {{-- Sans historique, on le dit sur la ligne de légende plutôt que d'afficher
             une variation inventée — et sans faire passer la valeur à la ligne. --}}
        <div class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
            {{ $kpi->sousTitre }}
            @if ($kpi->tendanceFormatee() === null && $kpi->tendanceIndisponible)
                <span class="text-zinc-400 dark:text-zinc-500" title="{{ $kpi->tendanceIndisponible }}">
                    · sans tendance
                </span>
            @endif
        </div>
    </div>
</div>
