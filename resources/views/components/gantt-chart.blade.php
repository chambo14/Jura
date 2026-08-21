@props(['gantt'])

@php
    use App\Enums\ProgressStatus;

    $barres = [
        'sky' => 'bg-sky-500', 'cyan' => 'bg-cyan-500', 'teal' => 'bg-teal-500',
        'emerald' => 'bg-emerald-500', 'amber' => 'bg-amber-500', 'orange' => 'bg-orange-500',
        'violet' => 'bg-violet-500', 'purple' => 'bg-purple-500', 'zinc' => 'bg-zinc-500',
    ];
@endphp

<div {{ $attributes->class('overflow-x-auto') }}>
    <div class="min-w-[52rem]">
        {{-- Bandeau des mois --}}
        <div class="flex border-b border-zinc-200 pb-1 dark:border-zinc-700">
            <div class="w-48 shrink-0 pe-3 text-xs font-medium text-zinc-500 dark:text-zinc-400">Phase MPM</div>
            <div class="relative h-5 flex-1">
                @foreach ($gantt->mois as $mois)
                    <div
                        class="absolute top-0 truncate border-s border-zinc-200 ps-1 text-[10px] uppercase text-zinc-500 dark:border-zinc-700 dark:text-zinc-400"
                        style="left: {{ $mois->left }}%; width: {{ $mois->width }}%"
                    >{{ $mois->label }}</div>
                @endforeach
            </div>
        </div>

        {{-- Une ligne par phase : barre prévue en fond, barre réelle par-dessus --}}
        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
            @foreach ($gantt->lignes as $ligne)
                <div class="flex items-center py-2">
                    <div class="w-48 shrink-0 pe-3">
                        <div class="truncate text-sm text-zinc-800 dark:text-zinc-100">{{ $ligne->libelle }}</div>
                        @if ($ligne->enRetard())
                            <div class="text-[11px] text-red-600 dark:text-red-400">+{{ $ligne->retard }} j de retard</div>
                        @endif
                    </div>

                    <div class="relative h-7 flex-1 rounded bg-zinc-50 dark:bg-zinc-800/50">
                        @if ($gantt->aujourdhui !== null)
                            <div class="absolute inset-y-0 z-20 w-px bg-red-500/70" style="left: {{ $gantt->aujourdhui }}%"></div>
                        @endif

                        @if ($ligne->prevu)
                            <div
                                class="absolute top-1 h-2 rounded-full border border-zinc-300 bg-zinc-200 dark:border-zinc-600 dark:bg-zinc-700"
                                style="left: {{ $ligne->prevu->left }}%; width: {{ $ligne->prevu->width }}%"
                                title="Prévu"
                            ></div>
                        @endif

                        @if ($ligne->reel)
                            <div
                                class="absolute top-3.5 h-3 rounded-full {{ $barres[$ligne->couleur] ?? $barres['zinc'] }} {{ $ligne->statut === ProgressStatus::EnCours ? 'ring-2 ring-blue-400/60' : '' }}"
                                style="left: {{ $ligne->reel->left }}%; width: {{ $ligne->reel->width }}%"
                                title="Réel — {{ $ligne->statut->label() }} ({{ number_format($ligne->avancement, 0) }} %)"
                            ></div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Jalons positionnés sur la même échelle --}}
        @if ($gantt->jalons->isNotEmpty())
            <div class="flex items-start border-t border-zinc-200 pt-2 dark:border-zinc-700">
                <div class="w-48 shrink-0 pe-3 text-xs font-medium text-zinc-500 dark:text-zinc-400">Jalons</div>
                <div class="relative h-8 flex-1">
                    @foreach ($gantt->jalons as $jalon)
                        <div class="absolute top-0 -translate-x-1/2" style="left: {{ $jalon->left }}%">
                            <flux:tooltip :content="$jalon->libelle.' — '.$jalon->date->format('d/m/Y')">
                                <div @class([
                                    'size-3 rotate-45 border',
                                    'border-red-600 bg-red-500' => $jalon->retard,
                                    'border-green-600 bg-green-500' => ! $jalon->retard && $jalon->atteint(),
                                    'border-zinc-500 bg-white dark:bg-zinc-900' => ! $jalon->retard && ! $jalon->atteint(),
                                ])></div>
                            </flux:tooltip>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="mt-3 flex flex-wrap items-center gap-4 text-[11px] text-zinc-500 dark:text-zinc-400">
            <span class="flex items-center gap-1.5"><span class="h-2 w-6 rounded-full bg-zinc-300 dark:bg-zinc-600"></span> Prévu</span>
            <span class="flex items-center gap-1.5"><span class="h-3 w-6 rounded-full bg-teal-500"></span> Réel</span>
            <span class="flex items-center gap-1.5"><span class="size-2.5 rotate-45 border border-zinc-500 bg-white dark:bg-zinc-900"></span> Jalon</span>
            <span class="flex items-center gap-1.5"><span class="h-3 w-px bg-red-500"></span> Aujourd'hui</span>
        </div>
    </div>
</div>
