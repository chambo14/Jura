<?php

use App\Enums\ProgressStatus;
use App\Models\FlashReport;
use App\Models\Project;
use App\Models\Task;
use App\Services\FlashReportBuilder;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Project $project;

    public string $semaine = '';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);

        $this->project = $project;
        $this->semaine = FlashReportBuilder::lundiDe()->format('Y-m-d');
    }

    #[Computed]
    public function peutRediger(): bool
    {
        return auth()->user()->can('update', $this->project);
    }

    /**
     * @return Collection<int, FlashReport>
     */
    #[Computed]
    public function rapports(): Collection
    {
        return $this->project->flashReports()->with('auteur')->get();
    }

    /**
     * Les tâches du projet, dans l'ordre où on veut les voir : ce qui reste à
     * faire d'abord, ce qui est fait ensuite.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function taches(): Collection
    {
        $rang = [
            ProgressStatus::Bloque->value => 1,
            ProgressStatus::EnCours->value => 2,
            ProgressStatus::NonDemarre->value => 3,
            ProgressStatus::Termine->value => 4,
            ProgressStatus::Annule->value => 5,
        ];

        return $this->project->tasks()
            ->with('assignee')
            ->get()
            ->sortBy([
                fn (Task $a, Task $b) => ($rang[$a->statut->value] ?? 9) <=> ($rang[$b->statut->value] ?? 9),
                fn (Task $a, Task $b) => ($a->date_echeance?->timestamp ?? PHP_INT_MAX) <=> ($b->date_echeance?->timestamp ?? PHP_INT_MAX),
                fn (Task $a, Task $b) => $a->libelle <=> $b->libelle,
            ])
            ->values();
    }

    /**
     * Identifiants des tâches déjà reprises par le dernier rapport : une tâche
     * créée après sa rédaction n'y figure pas, et il vaut mieux le dire que de
     * laisser croire qu'elle a été rapportée.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function tachesReprises(): array
    {
        return $this->dernier()?->items
            ->whereNotNull('task_id')
            ->pluck('task_id')
            ->all() ?? [];
    }

    #[Computed]
    public function dernier(): ?FlashReport
    {
        return $this->rapports()->first()?->load(['items', 'project.steps.workflowStep', 'project.members.user']);
    }

    /**
     * Crée (ou récupère) le rapport de la semaine choisie et ouvre sa rédaction.
     */
    public function preparer(): void
    {
        $this->authorize('update', $this->project);

        $this->validate(
            ['semaine' => ['required', 'date']],
            attributes: ['semaine' => 'semaine'],
        );

        $rapport = app(FlashReportBuilder::class)->preparer(
            $this->project->load(['tasks', 'milestones', 'deliverables']),
            \Illuminate\Support\Facades\Date::parse($this->semaine),
            auth()->id(),
        );

        $this->redirectRoute('flash-reports.edit', $rapport, navigate: true);
    }

    public function supprimer(int $id): void
    {
        $rapport = $this->project->flashReports()->findOrFail($id);

        $this->authorize('delete', $rapport);

        $rapport->delete();
        unset($this->rapports, $this->dernier);

        Flux::toast(variant: 'success', text: 'Flash report supprimé.');
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <flux:heading size="lg">Flash reports hebdomadaires</flux:heading>
            <flux:subheading>{{ $this->rapports()->count() }} rapport(s) archivé(s)</flux:subheading>
        </div>

        @if ($this->peutRediger())
            <div class="flex items-end gap-2">
                <flux:input wire:model="semaine" type="date" label="Semaine du (lundi)" class="w-48" />
                <flux:button wire:click="preparer" variant="primary" icon="sparkles">
                    Préparer le rapport
                </flux:button>
            </div>
        @endif
    </div>

    @if ($this->peutRediger())
        <flux:callout icon="information-circle" color="blue">
            Le rapport est pré-rempli automatiquement : activités réalisées sur la semaine choisie,
            activités à réaliser la semaine suivante, et points d'attention issus des dérives détectées.
        </flux:callout>
    @endif

    {{-- Les tâches du projet, telles qu'elles alimentent le rapport.

         Le rapport est figé au moment où on le prépare : il liste les tâches
         closes dans la semaine et celles attendues la suivante. Une tâche créée
         après ne s'y ajoute pas d'elle-même — d'où ce tableau, qui montre l'état
         réel du projet à l'instant où on le regarde, et signale ce que le
         dernier rapport n'a pas repris. --}}
    <div>
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
            <div>
                <flux:heading size="lg">Tâches du projet</flux:heading>
                <flux:subheading>{{ $this->taches()->count() }} tâche(s) · avancement et statut à l'instant</flux:subheading>
            </div>
            <flux:link :href="route('projets.show', $project).'?onglet=taches'" class="text-sm" wire:navigate>
                Gérer les tâches
            </flux:link>
        </div>

        <div class="overflow-hidden rounded-xl border border-zinc-200 shadow-sm dark:border-zinc-700">
            <table class="w-full text-sm">
                <thead class="bg-mpm-navy-tint text-xs uppercase tracking-wide text-mpm-navy-dark dark:bg-zinc-800/60 dark:text-zinc-300">
                    <tr>
                        <th class="px-4 py-2 text-start font-medium">Tâche</th>
                        <th class="px-4 py-2 text-start font-medium">Responsable</th>
                        <th class="px-4 py-2 text-start font-medium">Échéance</th>
                        <th class="px-4 py-2 text-start font-medium">Avancement</th>
                        <th class="px-4 py-2 text-start font-medium">Statut</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                    @forelse ($this->taches() as $tache)
                        <tr wire:key="tache-{{ $tache->id }}" class="align-top transition hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                            <td class="px-4 py-3">
                                <div class="font-medium text-zinc-900 dark:text-white">{{ $tache->libelle }}</div>
                                @if (! in_array($tache->id, $this->tachesReprises(), true) && $this->dernier())
                                    <div class="mt-0.5 text-xs text-zinc-400">Non reprise dans le dernier rapport</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs text-zinc-600 dark:text-zinc-300">
                                {{ $tache->assignee?->name ?? 'Non assignée' }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-xs tabular-nums">
                                @if ($tache->date_echeance)
                                    <span @class(['text-red-600 dark:text-red-400' => $tache->enRetard()])>
                                        {{ $tache->date_echeance->format('d/m/Y') }}
                                    </span>
                                @else
                                    <span class="text-zinc-400">Sans échéance</span>
                                @endif
                            </td>
                            <td class="w-40 px-4 py-3">
                                <x-progress-bar :value="$tache->avancement_pct" color="green" />
                            </td>
                            <td class="px-4 py-3">
                                <flux:badge :color="$tache->statut->color()" size="sm">{{ $tache->statut->label() }}</flux:badge>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10 text-center text-zinc-500 dark:text-zinc-400">
                                Aucune tâche sur ce projet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Historique --}}
    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-mpm-navy-tint text-xs uppercase tracking-wide text-mpm-navy-dark dark:bg-zinc-800/60 dark:text-zinc-300">
                <tr>
                    <th class="px-4 py-2 text-start font-medium">Semaine</th>
                    <th class="px-4 py-2 text-start font-medium">Avancement</th>
                    <th class="px-4 py-2 text-start font-medium">Santé</th>
                    <th class="px-4 py-2 text-start font-medium">Auteur</th>
                    <th class="px-4 py-2 text-start font-medium">Statut</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                @forelse ($this->rapports() as $rapport)
                    <tr wire:key="rapport-{{ $rapport->id }}">
                        <td class="px-4 py-3 tabular-nums">
                            <a href="{{ route('flash-reports.edit', $rapport) }}" wire:navigate class="font-medium hover:underline">
                                {{ $rapport->semaine_du->format('d/m/Y') }} → {{ $rapport->semaine_au->format('d/m/Y') }}
                            </a>
                        </td>
                        <td class="px-4 py-3 tabular-nums">{{ number_format($rapport->avancement_pct, 2, ',', ' ') }} %</td>
                        <td class="px-4 py-3">
                            @if ($rapport->sante)
                                <flux:badge :color="$rapport->sante->color()" size="sm">{{ $rapport->sante->label() }}</flux:badge>
                            @else
                                <span class="text-zinc-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">{{ $rapport->auteur?->name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <flux:badge :color="$rapport->statut->color()" size="sm">{{ $rapport->statut->label() }}</flux:badge>
                        </td>
                        <td class="px-4 py-3 text-end">
                            <flux:button :href="route('flash-reports.edit', $rapport)" icon="arrow-right" variant="ghost" size="xs" inset wire:navigate />
                            @can('delete', $rapport)
                                <flux:button
                                    wire:click="supprimer({{ $rapport->id }})"
                                    wire:confirm="Supprimer ce flash report ?"
                                    icon="trash"
                                    variant="ghost"
                                    size="xs"
                                    inset
                                />
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-zinc-500 dark:text-zinc-400">
                            Aucun flash report pour ce projet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Aperçu du dernier rapport --}}
    @if ($this->dernier())
        <div>
            <flux:heading size="lg" class="mb-3">Dernier rapport</flux:heading>
            <x-flash-slide :report="$this->dernier()" />
        </div>
    @endif
</div>
