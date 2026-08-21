@props([
    'value' => 0,
    'target' => null,
    'color' => 'blue',
    'showValue' => true,
])

@php
    $value = max(0, min(100, (float) $value));
    $target = $target === null ? null : max(0, min(100, (float) $target));

    $fills = [
        'blue' => 'bg-blue-500',
        'green' => 'bg-green-500',
        'amber' => 'bg-amber-500',
        'red' => 'bg-red-500',
        'zinc' => 'bg-zinc-400',
        'violet' => 'bg-violet-500',
    ];
@endphp

<div {{ $attributes->class('flex items-center gap-2') }}>
    <div class="relative h-2 flex-1 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
        <div class="h-full rounded-full {{ $fills[$color] ?? $fills['blue'] }}" style="width: {{ $value }}%"></div>

        @if ($target !== null)
            {{-- Repère de l'avancement théorique attendu à date. --}}
            <div
                class="absolute inset-y-0 w-0.5 bg-zinc-900 dark:bg-white"
                style="left: {{ $target }}%"
                title="Attendu à date : {{ number_format($target, 1, ',', ' ') }} %"
            ></div>
        @endif
    </div>

    @if ($showValue)
        <span class="w-14 shrink-0 text-right text-xs font-medium tabular-nums text-zinc-600 dark:text-zinc-300">
            {{ number_format($value, 1, ',', ' ') }} %
        </span>
    @endif
</div>
