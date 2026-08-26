@props(['suggestions'])

{{-- Propositions de rédaction. Un clic reprend le texte dans le champ : rien
     n'est enregistré tant que l'écran n'est pas validé. --}}
<div {{ $attributes->merge(['class' => 'space-y-2']) }}>
    @foreach ($suggestions as $rang => $proposition)
        <button
            type="button"
            wire:key="suggestion-{{ $rang }}"
            wire:click="appliquerSuggestion({{ $rang }})"
            class="block w-full rounded-lg border border-zinc-200 p-3 text-start text-sm hover:border-blue-400 dark:border-zinc-700 dark:hover:border-blue-500"
        >{{ $proposition }}</button>
    @endforeach

    <flux:text size="sm">Proposition d'un assistant : à relire et à corriger avant enregistrement.</flux:text>
</div>
