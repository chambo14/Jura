@props([
    'cible' => null,
    'texte' => 'Chargement…',
])

{{-- Voile d'attente, à poser dans un conteneur en `relative`.

     Il assombrit le contenu au lieu de le remplacer : l'utilisateur garde ses
     repères — colonnes, position dans la page — pendant que la liste se
     recalcule, et le retour ne le désoriente pas.

     `wire:loading.delay` laisse passer les réponses rapides sans rien
     afficher : un voile qui clignote pour trois centièmes de seconde est plus
     agité qu'informatif. Le `display: none` en dur évite le même clignotement
     avant que Livewire n'ait pris la main sur l'élément. --}}
<div
    wire:loading.delay.flex
    @if ($cible) wire:target="{{ $cible }}" @endif
    style="display: none"
    {{ $attributes->merge([
        'class' => 'absolute inset-0 z-10 items-center justify-center gap-2.5 bg-white/70 backdrop-blur-[1px] dark:bg-zinc-900/70',
    ]) }}
    aria-live="polite"
>
    <svg class="size-4 shrink-0 animate-spin text-blue-600 dark:text-blue-400" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" />
        <path class="opacity-90" d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
    </svg>

    <span class="text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ $texte }}</span>
</div>
