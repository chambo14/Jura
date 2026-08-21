<?php

use App\Enums\HealthStatus;
use App\Enums\ProjectCategory;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectHealthService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Projets')] class extends Component {
    #[Url(as: 'q', except: '')]
    public string $recherche = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $categorie = '';

    #[Url(except: '')]
    public string $statut = '';

    #[Url(except: '')]
    public string $sante = '';

    #[Url(except: '')]
    public string $chefProjet = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Project::class);
    }

    public function reinitialiser(): void
    {
        $this->reset('recherche', 'type', 'categorie', 'statut', 'sante', 'chefProjet');
    }

    /**
     * @return Collection<int, array{projet: Project, sante: \App\Support\ProjectHealth}>
     */
    #[Computed]
    public function lignes(): Collection
    {
        $service = app(ProjectHealthService::class);

        $projets = Project::query()
            ->visibleTo(auth()->user())
            ->with(['client', 'chefProjet', 'phase', 'workflowStep', 'milestones', 'tasks', 'deliverables'])
            ->when($this->recherche !== '', function ($query) {
                $terme = '%'.$this->recherche.'%';

                $query->where(fn ($q) => $q
                    ->where('nom', 'like', $terme)
                    ->orWhere('code', 'like', $terme)
                    ->orWhereHas('client', fn ($c) => $c->where('nom', 'like', $terme)->orWhere('sigle', 'like', $terme)));
            })
            ->when($this->type !== '', fn ($q) => $q->where('type_projet', $this->type))
            ->when($this->categorie !== '', fn ($q) => $q->where('categorie', $this->categorie))
            ->when($this->statut !== '', fn ($q) => $q->where('statut', $this->statut))
            ->when($this->chefProjet !== '', fn ($q) => $q->where('chef_projet_id', $this->chefProjet))
            ->ordreComite()
            ->get();

        return $projets
            ->map(fn (Project $projet) => ['projet' => $projet, 'sante' => $service->diagnostiquer($projet)])
            ->when($this->sante !== '', fn (Collection $lignes) => $lignes->filter(
                fn (array $ligne) => $ligne['sante']->statut->value === $this->sante,
            ))
            ->sortByDesc(fn (array $ligne) => $ligne['sante']->statut->weight())
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function chefsProjet(): Collection
    {
        return User::query()
            ->whereIn('id', Project::query()->visibleTo(auth()->user())->select('chef_projet_id'))
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function peutCreer(): bool
    {
        return auth()->user()->can('create', Project::class);
    }
}; ?>

<div class="flex w-full flex-1 flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <flux:heading size="xl">Projets</flux:heading>
            <flux:subheading>{{ $this->lignes()->count() }} projet(s) dans votre périmètre</flux:subheading>
        </div>

        @if ($this->peutCreer())
            <flux:button :href="route('projets.create')" icon="plus" variant="primary" wire:navigate>
                Nouveau projet
            </flux:button>
        @endif
    </div>

    {{-- Filtres --}}
    <div class="grid gap-3 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900 md:grid-cols-2 xl:grid-cols-7">
        <flux:input
            wire:model.live.debounce.300ms="recherche"
            icon="magnifying-glass"
            placeholder="Nom, code ou client…"
            class="xl:col-span-2"
            label="Recherche"
        />

        <flux:select wire:model.live="categorie" label="Rubrique comité" placeholder="Toutes">
            <flux:select.option value="">Toutes</flux:select.option>
            @foreach (ProjectCategory::ordonnees() as $cas)
                <flux:select.option :value="$cas->value">{{ $cas->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="type" label="Type" placeholder="Tous">
            <flux:select.option value="">Tous</flux:select.option>
            @foreach (ProjectType::cases() as $cas)
                <flux:select.option :value="$cas->value">{{ $cas->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="statut" label="Statut" placeholder="Tous">
            <flux:select.option value="">Tous</flux:select.option>
            @foreach (ProjectStatus::cases() as $cas)
                <flux:select.option :value="$cas->value">{{ $cas->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="sante" label="Santé" placeholder="Toutes">
            <flux:select.option value="">Toutes</flux:select.option>
            @foreach (HealthStatus::cases() as $cas)
                <flux:select.option :value="$cas->value">{{ $cas->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="chefProjet" label="Chef de projet" placeholder="Tous">
            <flux:select.option value="">Tous</flux:select.option>
            @foreach ($this->chefsProjet() as $chef)
                <flux:select.option :value="$chef->id">{{ $chef->name }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    {{-- Cartes projets --}}
    <div class="grid gap-4 lg:grid-cols-2">
        @forelse ($this->lignes() as $ligne)
            @php
                $projet = $ligne['projet'];
                $sante = $ligne['sante'];
                $restants = $projet->joursRestants();
            @endphp

            <a
                href="{{ route('projets.show', $projet) }}"
                wire:navigate
                class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-white p-4 transition hover:border-zinc-300 hover:shadow-sm dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-600"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-xs font-mono text-zinc-400">{{ $projet->code }}</span>
                            <flux:badge :color="$projet->type_projet->color()" size="sm">{{ $projet->type_projet->label() }}</flux:badge>
                            <flux:badge :color="$projet->categorie->color()" size="sm">{{ $projet->categorie->label() }}</flux:badge>
                        </div>
                        <h3 class="mt-1 truncate font-semibold text-zinc-900 dark:text-white">{{ $projet->nom }}</h3>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">
                            {{ $projet->client->displayName() }}@if ($projet->pays_domiciliation) · {{ $projet->pays_domiciliation }}@endif
                        </p>
                    </div>

                    <flux:badge :color="$sante->statut->color()" size="sm">{{ $sante->statut->label() }}</flux:badge>
                </div>

                <x-progress-bar
                    :value="$projet->avancement_pct"
                    :target="$sante->avancementTheoriquePct"
                    color="green"
                />

                <div class="grid grid-cols-3 gap-2 text-xs">
                    <div>
                        <div class="text-zinc-400">Phase</div>
                        <div class="truncate text-zinc-700 dark:text-zinc-200">{{ $projet->phase?->nom ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-400">Échéance</div>
                        <div @class([
                            'tabular-nums',
                            'text-red-600 dark:text-red-400' => $restants !== null && $restants < 0,
                            'text-zinc-700 dark:text-zinc-200' => $restants === null || $restants >= 0,
                        ])>{{ $projet->dateFinEffective()?->format('d/m/Y') ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-400">Chef de projet</div>
                        <div class="truncate text-zinc-700 dark:text-zinc-200">{{ $projet->chefProjet?->name ?? '—' }}</div>
                    </div>
                </div>

                @if ($sante->nombreAlertes() > 0)
                    <div class="flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                        <flux:icon name="exclamation-triangle" variant="micro" class="text-amber-500" />
                        {{ $sante->alertes->first()->libelle }}
                        @if ($sante->nombreAlertes() > 1)
                            <span class="text-zinc-400">+{{ $sante->nombreAlertes() - 1 }}</span>
                        @endif
                    </div>
                @endif
            </a>
        @empty
            <div class="col-span-full rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700">
                <flux:text>Aucun projet ne correspond à ces critères.</flux:text>
                <flux:button wire:click="reinitialiser" variant="ghost" size="sm" class="mt-3">
                    Réinitialiser les filtres
                </flux:button>
            </div>
        @endforelse
    </div>
</div>
