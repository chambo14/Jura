@props([
    'label',
    'value',
    'sub' => null,
    'icon' => null,
    'color' => 'zinc',
    'href' => null,
])

@php
    $tones = [
        'zinc' => 'text-zinc-600 dark:text-zinc-300 bg-zinc-100 dark:bg-zinc-800',
        'blue' => 'text-blue-600 dark:text-blue-300 bg-blue-100 dark:bg-blue-950',
        'green' => 'text-green-600 dark:text-green-300 bg-green-100 dark:bg-green-950',
        'amber' => 'text-amber-600 dark:text-amber-300 bg-amber-100 dark:bg-amber-950',
        'red' => 'text-red-600 dark:text-red-300 bg-red-100 dark:bg-red-950',
        'violet' => 'text-violet-600 dark:text-violet-300 bg-violet-100 dark:bg-violet-950',
    ];
    $tone = $tones[$color] ?? $tones['zinc'];
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }}
    @if ($href) href="{{ $href }}" wire:navigate @endif
    {{ $attributes->class([
        'flex items-start gap-4 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900',
        'transition hover:border-zinc-300 dark:hover:border-zinc-600' => $href,
    ]) }}
>
    @if ($icon)
        <div class="flex size-10 shrink-0 items-center justify-center rounded-lg {{ $tone }}">
            <flux:icon :name="$icon" variant="mini" />
        </div>
    @endif

    <div class="min-w-0 flex-1">
        <div class="truncate text-sm text-zinc-500 dark:text-zinc-400">{{ $label }}</div>
        <div class="mt-0.5 text-2xl font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $value }}</div>
        @if ($sub)
            <div class="mt-0.5 truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $sub }}</div>
        @endif
    </div>
</{{ $tag }}>
