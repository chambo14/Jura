@props([
    'attachments',
    'peutRetirer' => false,
    'rattachement' => false,
    'vide' => 'Aucun fichier déposé.',
])

{{-- Liste des pièces jointes. Le nom reste cliquable même si le fichier a
     disparu du disque : c'est le contrôleur qui répond 404, pas la liste qui
     masque la trace du dépôt. --}}
<div {{ $attributes->merge(['class' => 'divide-y divide-zinc-100 dark:divide-zinc-800']) }}>
    @forelse ($attachments as $piece)
        <div wire:key="piece-{{ $piece->id }}" class="flex items-center gap-3 py-2.5">
            <flux:icon :name="$piece->icone()" variant="mini" class="shrink-0 text-zinc-400" />

            <div class="min-w-0 flex-1">
                <a
                    href="{{ route('documents.download', $piece) }}"
                    class="block truncate text-sm font-medium hover:underline"
                >{{ $piece->nom }}</a>

                <div class="truncate text-xs text-zinc-500 dark:text-zinc-400">
                    {{ $piece->tailleLisible() }}
                    · {{ $piece->created_at?->format('d/m/Y') }}
                    @if ($piece->deposePar) · {{ $piece->deposePar->name }} @endif
                    @if ($rattachement) · {{ $piece->rattachement() }} @endif
                </div>

                @if ($piece->description)
                    <div class="truncate text-xs text-zinc-600 dark:text-zinc-300">{{ $piece->description }}</div>
                @endif
            </div>

            @if ($peutRetirer)
                <flux:button
                    wire:click="retirerFichier({{ $piece->id }})"
                    wire:confirm="Retirer définitivement ce fichier ?"
                    icon="trash"
                    variant="ghost"
                    size="xs"
                    inset
                />
            @endif
        </div>
    @empty
        <div class="py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ $vide }}</div>
    @endforelse
</div>
