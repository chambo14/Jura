@props([
    'steps',
    'compact' => false,
])

@php
    use App\Enums\ProgressStatus;
@endphp

{{-- Reprend le bandeau « PHASE ACTUELLE DU PROJET » des flash reports. --}}
<ol {{ $attributes->class('flex flex-wrap gap-1.5') }}>
    @foreach ($steps as $step)
        @php
            $statut = $step->statut;
            $classes = match ($statut) {
                ProgressStatus::Termine => 'border-green-500/40 bg-green-50 text-green-800 dark:bg-green-950/60 dark:text-green-200',
                ProgressStatus::EnCours => 'border-blue-500 bg-blue-600 text-white shadow-sm dark:bg-blue-600',
                ProgressStatus::Bloque => 'border-red-500/40 bg-red-50 text-red-800 dark:bg-red-950/60 dark:text-red-200',
                default => 'border-zinc-200 bg-white text-zinc-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-400',
            };
        @endphp

        <li
            class="flex items-center gap-1.5 rounded-md border px-2.5 py-1 {{ $classes }} {{ $compact ? 'text-[11px]' : 'text-xs' }}"
            title="{{ $step->intitule() }} — {{ $statut->label() }}"
        >
            @if ($statut === ProgressStatus::Termine)
                <flux:icon name="check" variant="micro" class="shrink-0" />
            @elseif ($statut === ProgressStatus::Bloque)
                <flux:icon name="exclamation-triangle" variant="micro" class="shrink-0" />
            @endif

            <span class="font-medium">{{ $step->intitule() }}</span>

            @if ($step->annotation)
                <span class="opacity-75">{{ $step->annotation }}</span>
            @endif
        </li>
    @endforeach
</ol>
