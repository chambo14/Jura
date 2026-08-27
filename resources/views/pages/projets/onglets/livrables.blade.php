<?php

use App\Enums\DeliverableStatus;
use App\Models\Deliverable;
use App\Models\Phase;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectProvisioner;
use App\Support\Echeances\Echeances;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Project $project;

    public string $filtreStatut = '';

    public bool $seulementObligatoires = false;

    public ?int $livrableEnEdition = null;

    public string $livrableNom = '';

    public string $livrablePhase = '';

    public string $livrableStatut = DeliverableStatus::AProduire->value;

    public string $livrableResponsable = '';

    public string $livrableDatePrevue = '';

    public string $livrableDateLivraison = '';

    public string $livrableVersion = '';

    public string $livrableLien = '';

    public bool $livrableObligatoire = true;

    public string $livrableCommentaire = '';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);

        $this->project = $project;
    }

    #[Computed]
    public function peutContribuer(): bool
    {
        return auth()->user()->can('contribute', $this->project);
    }

    #[Computed]
    public function peutModifier(): bool
    {
        return auth()->user()->can('update', $this->project);
    }

    /**
     * Livrables regroupés par phase MPM, dans l'ordre de la méthodologie.
     *
     * @return Collection<int, array{phase: Phase, livrables: Collection<int, Deliverable>}>
     */
    #[Computed]
    public function groupes(): Collection
    {
        return $this->project->deliverables()
            ->with(['phase', 'responsable', 'type'])
            ->when($this->filtreStatut !== '', fn ($q) => $q->where('statut', $this->filtreStatut))
            ->when($this->seulementObligatoires, fn ($q) => $q->where('obligatoire', true))
            ->get()
            ->sortBy([
                fn (Deliverable $a, Deliverable $b) => $a->phase->ordre <=> $b->phase->ordre,
                fn (Deliverable $a, Deliverable $b) => $a->ordre <=> $b->ordre,
            ])
            ->groupBy('phase_id')
            ->map(fn (Collection $livrables) => [
                'phase' => $livrables->first()->phase,
                'livrables' => $livrables->values(),
            ])
            ->values();
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function synthese(): array
    {
        $tous = $this->project->deliverables;

        return [
            'total' => $tous->count(),
            'disponibles' => $tous->filter(fn ($l) => $l->estDisponible())->count(),
            'echeances' => Echeances::de($tous),
            'obligatoires_manquants' => $tous->filter(fn ($l) => $l->obligatoire && $l->statut->isOutstanding())->count(),
        ];
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function responsablesPossibles(): Collection
    {
        return User::actifs()->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Phase>
     */
    #[Computed]
    public function phases(): Collection
    {
        return Phase::ordered()->get();
    }

    /**
     * Changement de statut direct depuis la liste.
     */
    public function changerStatut(int $id, string $statut): void
    {
        $this->authorize('contribute', $this->project);

        $livrable = $this->project->deliverables()->findOrFail($id);
        $nouveau = DeliverableStatus::from($statut);

        $livrable->update([
            'statut' => $nouveau,
            'date_livraison' => in_array($nouveau, [DeliverableStatus::Soumis, DeliverableStatus::Valide], true)
                ? ($livrable->date_livraison ?? now())
                : null,
        ]);

        $this->project->load('deliverables');
        unset($this->groupes, $this->synthese);
    }

    public function nouveauLivrable(): void
    {
        $this->authorize('update', $this->project);

        $this->reset(
            'livrableEnEdition', 'livrableNom', 'livrableResponsable', 'livrableDatePrevue',
            'livrableDateLivraison', 'livrableVersion', 'livrableLien', 'livrableCommentaire',
        );
        $this->livrableStatut = DeliverableStatus::AProduire->value;
        $this->livrableObligatoire = true;
        $this->livrablePhase = (string) ($this->project->phase_id ?? $this->phases()->first()?->id);

        Flux::modal('livrable')->show();
    }

    public function editerLivrable(int $id): void
    {
        $this->authorize('contribute', $this->project);

        $livrable = $this->project->deliverables()->findOrFail($id);

        $this->livrableEnEdition = $livrable->id;
        $this->livrableNom = $livrable->nom;
        $this->livrablePhase = (string) $livrable->phase_id;
        $this->livrableStatut = $livrable->statut->value;
        $this->livrableResponsable = (string) ($livrable->responsable_id ?? '');
        $this->livrableDatePrevue = $livrable->date_prevue?->format('Y-m-d') ?? '';
        $this->livrableDateLivraison = $livrable->date_livraison?->format('Y-m-d') ?? '';
        $this->livrableVersion = $livrable->version ?? '';
        $this->livrableLien = $livrable->lien ?? '';
        $this->livrableObligatoire = $livrable->obligatoire;
        $this->livrableCommentaire = $livrable->commentaire ?? '';

        Flux::modal('livrable')->show();
    }

    public function enregistrerLivrable(): void
    {
        $this->authorize('contribute', $this->project);

        $donnees = $this->validate([
            'livrableNom' => ['required', 'string', 'max:255'],
            'livrablePhase' => ['required', 'exists:phases,id'],
            'livrableStatut' => ['required', 'string'],
            'livrableResponsable' => ['nullable', 'exists:users,id'],
            'livrableDatePrevue' => ['nullable', 'date'],
            'livrableDateLivraison' => ['nullable', 'date'],
            'livrableVersion' => ['nullable', 'string', 'max:50'],
            'livrableLien' => ['nullable', 'url', 'max:2048'],
            'livrableObligatoire' => ['boolean'],
            'livrableCommentaire' => ['nullable', 'string', 'max:2000'],
        ], attributes: [
            'livrableNom' => 'nom du livrable',
            'livrablePhase' => 'phase',
            'livrableLien' => 'lien',
        ]);

        $attributs = [
            'nom' => $donnees['livrableNom'],
            'phase_id' => $donnees['livrablePhase'],
            'statut' => $donnees['livrableStatut'],
            'responsable_id' => $donnees['livrableResponsable'] ?: null,
            'date_prevue' => $donnees['livrableDatePrevue'] ?: null,
            'date_livraison' => $donnees['livrableDateLivraison'] ?: null,
            'version' => $donnees['livrableVersion'] ?: null,
            'lien' => $donnees['livrableLien'] ?: null,
            'obligatoire' => $donnees['livrableObligatoire'],
            'commentaire' => $donnees['livrableCommentaire'] ?: null,
        ];

        if ($this->livrableEnEdition) {
            $this->project->deliverables()->whereKey($this->livrableEnEdition)->update($attributs);
        } else {
            $this->project->deliverables()->create($attributs);
        }

        $this->project->load('deliverables');
        unset($this->groupes, $this->synthese);

        Flux::modal('livrable')->close();
        Flux::toast(variant: 'success', text: 'Livrable enregistré.');
    }

    public function supprimerLivrable(int $id): void
    {
        $this->authorize('update', $this->project);

        $this->project->deliverables()->whereKey($id)->delete();
        $this->project->load('deliverables');
        unset($this->groupes, $this->synthese);

        Flux::toast(variant: 'success', text: 'Livrable supprimé.');
    }

    /**
     * Recrée les livrables du référentiel MPM absents du projet.
     */
    public function resynchroniser(): void
    {
        $this->authorize('update', $this->project);

        $ajoutes = app(ProjectProvisioner::class)->resynchroniserLivrables($this->project);

        $this->project->load('deliverables');
        unset($this->groupes, $this->synthese);

        Flux::toast(
            variant: 'success',
            text: $ajoutes > 0
                ? "{$ajoutes} livrable(s) du référentiel MPM ajouté(s)."
                : 'Le projet couvre déjà tout le référentiel MPM.',
        );
    }
}; ?>

<div class="flex flex-col gap-6">
    {{-- Synthèse et filtres --}}
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat label="Livrables attendus" :value="$this->synthese()['total']" icon="document-text" color="blue" />
        <x-stat label="Disponibles" :value="$this->synthese()['disponibles']" icon="check-circle" color="green" />
        <x-stat label="Obligatoires manquants" :value="$this->synthese()['obligatoires_manquants']" icon="exclamation-triangle" color="amber" />
        {{-- Sans échéance saisie, ce compteur n'affiche pas « 0 » : il dit qu'il
             ne mesure rien. --}}
        @php $echeances = $this->synthese()['echeances']; @endphp
        <x-stat
            label="En retard"
            :value="$echeances->valeur()"
            :sub="$echeances->precision()"
            icon="clock"
            :color="$echeances->couleur()"
        />
    </div>

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div class="flex flex-wrap items-end gap-3">
            <flux:select wire:model.live="filtreStatut" label="Statut" class="w-56">
                <flux:select.option value="">Tous les statuts</flux:select.option>
                @foreach (DeliverableStatus::cases() as $cas)
                    <flux:select.option :value="$cas->value">{{ $cas->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:checkbox wire:model.live="seulementObligatoires" label="Obligatoires uniquement" class="pb-2" />
        </div>

        @if ($this->peutModifier())
            <div class="flex gap-2">
                <flux:button wire:click="resynchroniser" variant="ghost" size="sm" icon="arrow-path">
                    Recharger le référentiel MPM
                </flux:button>
                <flux:button wire:click="nouveauLivrable" variant="primary" size="sm" icon="plus">
                    Ajouter un livrable
                </flux:button>
            </div>
        @endif
    </div>

    {{-- Livrables par phase --}}
    @forelse ($this->groupes() as $groupe)
        <section class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700" wire:key="phase-{{ $groupe['phase']->id }}">
            <header class="flex items-center justify-between gap-3 border-b border-zinc-200 bg-zinc-50 px-4 py-2.5 dark:border-zinc-700 dark:bg-zinc-800/60">
                <div class="flex items-center gap-2">
                    <span class="text-xs font-mono text-zinc-400">{{ $groupe['phase']->ordre }}</span>
                    <h3 class="font-semibold text-zinc-800 dark:text-zinc-100">{{ $groupe['phase']->nom }}</h3>
                </div>
                <flux:text size="sm">
                    {{ $groupe['livrables']->filter(fn ($l) => $l->estDisponible())->count() }}/{{ $groupe['livrables']->count() }} disponibles
                </flux:text>
            </header>

            <div class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                @foreach ($groupe['livrables'] as $livrable)
                    <div class="flex flex-wrap items-center gap-3 px-4 py-3" wire:key="livrable-{{ $livrable->id }}">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                @if ($livrable->lien)
                                    <flux:link :href="$livrable->lien" target="_blank" rel="noopener" class="font-medium">
                                        {{ $livrable->nom }}
                                    </flux:link>
                                @else
                                    <span class="font-medium">{{ $livrable->nom }}</span>
                                @endif

                                @if (! $livrable->obligatoire)
                                    <flux:badge color="zinc" size="sm">Facultatif</flux:badge>
                                @endif
                                @if ($livrable->version)
                                    <span class="text-xs text-zinc-400">v{{ $livrable->version }}</span>
                                @endif
                            </div>

                            <div class="mt-0.5 flex flex-wrap gap-x-3 text-xs text-zinc-500 dark:text-zinc-400">
                                <span>Responsable : {{ $livrable->responsable?->name ?? $livrable->type?->responsable ?? '—' }}</span>
                                @if ($livrable->date_prevue)
                                    <span @class(['text-red-600 dark:text-red-400' => $livrable->enRetard()])>
                                        Attendu le {{ $livrable->date_prevue->format('d/m/Y') }}
                                    </span>
                                @endif
                                @if ($livrable->date_livraison)
                                    <span>Livré le {{ $livrable->date_livraison->format('d/m/Y') }}</span>
                                @endif
                            </div>
                        </div>

                        @if ($this->peutContribuer())
                            <flux:select
                                wire:change="changerStatut({{ $livrable->id }}, $event.target.value)"
                                size="sm"
                                class="w-48"
                            >
                                @foreach (DeliverableStatus::cases() as $cas)
                                    <flux:select.option :value="$cas->value" :selected="$livrable->statut === $cas">
                                        {{ $cas->label() }}
                                    </flux:select.option>
                                @endforeach
                            </flux:select>

                            <flux:button wire:click="editerLivrable({{ $livrable->id }})" icon="pencil-square" variant="ghost" size="xs" inset />
                        @else
                            <flux:badge :color="$livrable->statut->color()" size="sm">{{ $livrable->statut->label() }}</flux:badge>
                        @endif

                        @if ($this->peutModifier())
                            <flux:button
                                wire:click="supprimerLivrable({{ $livrable->id }})"
                                wire:confirm="Supprimer ce livrable ?"
                                icon="trash"
                                variant="ghost"
                                size="xs"
                                inset
                            />
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @empty
        <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700">
            <flux:text>Aucun livrable ne correspond à ces critères.</flux:text>
        </div>
    @endforelse

    {{-- Modale livrable --}}
    <flux:modal name="livrable" class="md:w-[32rem]">
        <form wire:submit="enregistrerLivrable" class="space-y-5">
            <flux:heading size="lg">{{ $livrableEnEdition ? 'Modifier le livrable' : 'Nouveau livrable' }}</flux:heading>

            <flux:input wire:model="livrableNom" label="Nom du livrable" required />

            <div class="grid grid-cols-2 gap-3">
                <flux:select wire:model="livrablePhase" label="Phase MPM">
                    @foreach ($this->phases() as $phase)
                        <flux:select.option :value="$phase->id">{{ $phase->nom }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="livrableStatut" label="Statut">
                    @foreach (DeliverableStatus::cases() as $cas)
                        <flux:select.option :value="$cas->value">{{ $cas->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:select wire:model="livrableResponsable" label="Responsable" placeholder="Non assigné">
                <flux:select.option value="">Non assigné</flux:select.option>
                @foreach ($this->responsablesPossibles() as $utilisateur)
                    <flux:select.option :value="$utilisateur->id">{{ $utilisateur->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="grid grid-cols-2 gap-3">
                <flux:input wire:model="livrableDatePrevue" type="date" label="Date prévue" />
                <flux:input wire:model="livrableDateLivraison" type="date" label="Date de livraison" />
            </div>

            <div class="grid grid-cols-2 gap-3">
                <flux:input wire:model="livrableVersion" label="Version" placeholder="1.0" />
                <flux:input wire:model="livrableLien" type="url" label="Lien" placeholder="https://…" />
            </div>

            <flux:textarea wire:model="livrableCommentaire" label="Commentaire" rows="2" />

            <flux:checkbox wire:model="livrableObligatoire" label="Livrable obligatoire" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Annuler</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Enregistrer</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
