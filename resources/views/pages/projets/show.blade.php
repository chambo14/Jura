<?php

use App\Models\Project;
use App\Services\ProjectHealthService;
use App\Support\ProjectHealth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Project $project;

    public string $onglet = 'synthese';

    /**
     * @var array<string, array{libelle: string, icone: string}>
     */
    public array $onglets = [
        'synthese' => ['libelle' => 'Synthèse', 'icone' => 'squares-2x2'],
        'planning' => ['libelle' => 'Planning & délais', 'icone' => 'calendar-days'],
        'ressources' => ['libelle' => 'Ressources', 'icone' => 'users'],
        'livrables' => ['libelle' => 'Livrables', 'icone' => 'document-text'],
        'taches' => ['libelle' => 'Tâches', 'icone' => 'check-circle'],
        'rapports' => ['libelle' => 'Flash reports', 'icone' => 'bolt'],
    ];

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);

        $this->project = $project;
        $this->onglet = request()->query('onglet', 'synthese');

        if (! array_key_exists($this->onglet, $this->onglets)) {
            $this->onglet = 'synthese';
        }
    }

    public function choisirOnglet(string $onglet): void
    {
        if (array_key_exists($onglet, $this->onglets)) {
            $this->onglet = $onglet;
        }
    }

    #[Computed]
    public function sante(): ProjectHealth
    {
        return app(ProjectHealthService::class)->diagnostiquer(
            $this->project->load(['milestones', 'tasks', 'deliverables']),
        );
    }

    #[Computed]
    public function peutModifier(): bool
    {
        return auth()->user()->can('update', $this->project);
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        $view->title($this->project->nom);
    }
}; ?>

<div class="flex w-full flex-1 flex-col gap-6">
    {{-- En-tête projet --}}
    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <flux:breadcrumbs>
                    <flux:breadcrumbs.item :href="route('projets.index')" wire:navigate>Projets</flux:breadcrumbs.item>
                    <flux:breadcrumbs.item>{{ $project->code }}</flux:breadcrumbs.item>
                </flux:breadcrumbs>

                <flux:heading size="xl" class="mt-2">{{ $project->nom }}</flux:heading>

                <div class="mt-1 flex flex-wrap items-center gap-2">
                    <flux:badge :color="$project->type_projet->color()" size="sm">{{ $project->type_projet->label() }}</flux:badge>
                    <flux:badge :color="$project->statut->color()" size="sm">{{ $project->statut->label() }}</flux:badge>
                    <flux:badge :color="$this->sante()->statut->color()" size="sm">{{ $this->sante()->statut->label() }}</flux:badge>
                    <flux:text size="sm">
                        {{ $project->client->displayName() }}@if ($project->pays_domiciliation) · {{ $project->pays_domiciliation }}@endif
                    </flux:text>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                @if ($this->peutModifier())
                    <flux:button :href="route('projets.edit', $project)" icon="pencil-square" variant="filled" size="sm" wire:navigate>
                        Modifier
                    </flux:button>
                @endif
                <flux:button wire:click="choisirOnglet('rapports')" icon="bolt" variant="primary" size="sm">
                    Flash report
                </flux:button>
            </div>
        </div>

        @if ($project->description)
            <flux:text>{{ $project->description }}</flux:text>
        @endif
    </div>

    {{-- Navigation par onglets --}}
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
        @switch($onglet)
            @case('planning')
                <livewire:pages::projets.onglets.planning :project="$project" :key="'planning-'.$project->id" />
                @break

            @case('ressources')
                <livewire:pages::projets.onglets.ressources :project="$project" :key="'ressources-'.$project->id" />
                @break

            @case('livrables')
                <livewire:pages::projets.onglets.livrables :project="$project" :key="'livrables-'.$project->id" />
                @break

            @case('taches')
                <livewire:pages::projets.onglets.taches :project="$project" :key="'taches-'.$project->id" />
                @break

            @case('rapports')
                <livewire:pages::projets.onglets.rapports :project="$project" :key="'rapports-'.$project->id" />
                @break

            @default
                <livewire:pages::projets.onglets.synthese :project="$project" :key="'synthese-'.$project->id" />
        @endswitch
    </div>
</div>
