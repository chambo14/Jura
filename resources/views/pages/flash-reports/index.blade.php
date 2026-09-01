<?php

use App\Enums\FlashReportStatus;
use App\Models\FlashReport;
use App\Models\Project;
use App\Services\FlashReportBuilder;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Flash reports')] class extends Component {
    #[Url(except: '')]
    public string $semaine = '';

    public function mount(): void
    {
        if ($this->semaine === '') {
            $this->semaine = FlashReportBuilder::lundiDe()->format('Y-m-d');
        }
    }

    #[Computed]
    public function lundi(): CarbonInterface
    {
        return FlashReportBuilder::lundiDe(Date::parse($this->semaine));
    }

    public function semainePrecedente(): void
    {
        $this->semaine = $this->lundi()->subWeek()->format('Y-m-d');
        unset($this->lundi, $this->couverture);
    }

    public function semaineSuivante(): void
    {
        $this->semaine = $this->lundi()->addWeek()->format('Y-m-d');
        unset($this->lundi, $this->couverture);
    }

    /**
     * Un projet par ligne, avec son rapport de la semaine s'il existe.
     *
     * @return Collection<int, array{projet: Project, rapport: FlashReport|null}>
     */
    #[Computed]
    public function couverture(): Collection
    {
        $projets = Project::query()
            ->visibleTo(auth()->user())
            ->actifs()
            ->with(['client', 'chefProjet'])
            ->ordreComite()
            ->get();

        $rapports = FlashReport::query()
            ->whereIn('project_id', $projets->pluck('id'))
            ->pourSemaine($this->lundi())
            ->with('auteur')
            ->get()
            ->keyBy('project_id');

        return $projets
            ->map(fn (Project $projet) => [
                'projet' => $projet,
                'rapport' => $rapports->get($projet->id),
            ])
            ->values();
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function indicateurs(): array
    {
        $couverture = $this->couverture();

        return [
            'attendus' => $couverture->count(),
            'manquants' => $couverture->whereNull('rapport')->count(),
            'brouillons' => $couverture->filter(
                fn (array $l) => $l['rapport']?->statut === FlashReportStatus::Brouillon,
            )->count(),
            'publies' => $couverture->filter(
                fn (array $l) => $l['rapport']?->statut === FlashReportStatus::Publie,
            )->count(),
        ];
    }

    /**
     * Génère les rapports manquants de la semaine pour les projets que l'on pilote.
     */
    public function preparerManquants(): void
    {
        $builder = app(FlashReportBuilder::class);
        $crees = 0;

        foreach ($this->couverture() as $ligne) {
            if ($ligne['rapport'] || auth()->user()->cannot('update', $ligne['projet'])) {
                continue;
            }

            $builder->preparer(
                $ligne['projet']->load(['tasks', 'milestones', 'deliverables']),
                $this->lundi(),
                auth()->id(),
            );

            $crees++;
        }

        unset($this->couverture, $this->indicateurs);

        Flux::toast(
            variant: $crees > 0 ? 'success' : 'warning',
            text: $crees > 0
                ? "{$crees} flash report(s) préparé(s)."
                : 'Aucun rapport à préparer sur vos projets.',
        );
    }
}; ?>

<div class="flex w-full flex-1 flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <flux:heading size="xl">Flash reports</flux:heading>
            <flux:subheading>
                Semaine du {{ $this->lundi()->translatedFormat('j F Y') }}
                au {{ $this->lundi()->addDays(4)->translatedFormat('j F Y') }}
            </flux:subheading>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:button wire:click="semainePrecedente" icon="chevron-left" variant="ghost" size="sm" />
            <flux:button wire:click="semaineSuivante" icon="chevron-right" variant="ghost" size="sm" />
            <flux:button wire:click="preparerManquants" icon="sparkles" variant="filled" size="sm">
                Préparer les manquants
            </flux:button>
            <flux:button :href="route('comite')" icon="presentation-chart-bar" variant="primary" size="sm" wire:navigate>
                Mode comité
            </flux:button>
        </div>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat label="Projets suivis" :value="$this->indicateurs()['attendus']" icon="rectangle-stack" color="blue" />
        <x-stat label="Publiés" :value="$this->indicateurs()['publies']" icon="check-circle" color="green" />
        <x-stat label="Brouillons" :value="$this->indicateurs()['brouillons']" icon="pencil-square" color="amber" />
        <x-stat label="Non rédigés" :value="$this->indicateurs()['manquants']" icon="exclamation-triangle" color="red" />
    </div>

    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-mpm-navy-tint text-xs uppercase tracking-wide text-mpm-navy-dark dark:bg-zinc-800/60 dark:text-zinc-300">
                <tr>
                    <th class="px-4 py-2 text-start font-medium">Projet</th>
                    <th class="px-4 py-2 text-start font-medium">Chef de projet</th>
                    <th class="px-4 py-2 text-start font-medium">Avancement</th>
                    <th class="px-4 py-2 text-start font-medium">Santé</th>
                    <th class="px-4 py-2 text-start font-medium">Rapport</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                @foreach ($this->couverture() as $ligne)
                    @php
                        $projet = $ligne['projet'];
                        $rapport = $ligne['rapport'];
                    @endphp

                    <tr wire:key="couverture-{{ $projet->id }}">
                        <td class="px-4 py-3">
                            <a href="{{ route('projets.show', $projet) }}" wire:navigate class="font-medium hover:underline">
                                {{ $projet->nom }}
                            </a>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $projet->client->sigle ?? $projet->client->nom }}</div>
                        </td>
                        <td class="px-4 py-3">{{ $projet->chefProjet?->name ?? '—' }}</td>
                        <td class="px-4 py-3 tabular-nums">
                            {{ number_format($rapport?->avancement_pct ?? $projet->avancement_pct, 2, ',', ' ') }} %
                        </td>
                        <td class="px-4 py-3">
                            @if ($rapport?->sante)
                                <flux:badge :color="$rapport->sante->color()" size="sm">{{ $rapport->sante->label() }}</flux:badge>
                            @else
                                <span class="text-zinc-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if ($rapport)
                                <flux:badge :color="$rapport->statut->color()" size="sm">{{ $rapport->statut->label() }}</flux:badge>
                            @else
                                <flux:badge color="red" size="sm">Non rédigé</flux:badge>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-end">
                            @if ($rapport)
                                <flux:button :href="route('flash-reports.edit', $rapport)" icon="arrow-right" variant="ghost" size="xs" inset wire:navigate />
                            @else
                                <flux:button
                                    :href="route('projets.show', $projet).'?onglet=rapports'"
                                    icon="plus"
                                    variant="ghost"
                                    size="xs"
                                    inset
                                    wire:navigate
                                />
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
