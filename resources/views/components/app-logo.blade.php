@props([
    'sidebar' => false,
])

@php
    // La marque est bicolore : elle se pose sur un fond clair, jamais sur l'accent.
    $pastille = 'flex aspect-square size-8 items-center justify-center overflow-hidden rounded-md bg-white ring-1 ring-zinc-200 dark:ring-zinc-700';
@endphp

@if ($sidebar)
    <flux:sidebar.brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" :class="$pastille">
            <x-app-logo-icon class="size-5" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" :class="$pastille">
            <x-app-logo-icon class="size-5" />
        </x-slot>
    </flux:brand>
@endif
