<?php

use App\Models\Profile;
use App\Models\User;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Utilisateurs')] class extends Component {
    public string $onglet = 'comptes';

    /**
     * @var array<string, array{libelle: string, icone: string}>
     */
    public array $onglets = [
        'comptes' => ['libelle' => 'Comptes', 'icone' => 'users'],
        'profils' => ['libelle' => "Profils d'accès", 'icone' => 'shield-check'],
    ];

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);

        $this->onglet = request()->query('onglet', 'comptes');

        if (! array_key_exists($this->onglet, $this->onglets)) {
            $this->onglet = 'comptes';
        }
    }

    public function choisirOnglet(string $onglet): void
    {
        if (array_key_exists($onglet, $this->onglets)) {
            $this->onglet = $onglet;
        }
    }
}; ?>

<div class="flex w-full flex-1 flex-col gap-6">
    <div>
        <flux:heading size="xl">Utilisateurs</flux:heading>
        <flux:subheading>
            {{ $onglet === 'profils'
                ? 'Définissez qui peut voir, piloter et administrer'
                : "Comptes d'accès à l'application" }}
        </flux:subheading>
    </div>

    <flux:navbar class="-mb-px border-b border-zinc-200 dark:border-zinc-700">
        @foreach ($onglets as $cle => $meta)
            <flux:navbar.item
                :icon="$meta['icone']"
                :current="$onglet === $cle"
                wire:click="choisirOnglet('{{ $cle }}')"
                class="cursor-pointer"
            >
                {{ $meta['libelle'] }}
            </flux:navbar.item>
        @endforeach
    </flux:navbar>

    <div wire:key="onglet-{{ $onglet }}">
        @if ($onglet === 'profils')
            <livewire:pages::utilisateurs.onglets.profils key="profils" />
        @else
            <livewire:pages::utilisateurs.onglets.comptes key="comptes" />
        @endif
    </div>
</div>
