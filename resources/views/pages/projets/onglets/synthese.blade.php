<?php

use App\Enums\DeliverableStatus;
use App\Enums\MemberRole;
use App\Enums\ProgressStatus;
use App\Models\Project;
use App\Services\ProjectHealthService;
use App\Support\Echeances\Echeances;
use App\Support\ProjectHealth;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Project $project;

    public float $avancement = 0;

    public float $tauxRealisation = 0;

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);

        $this->project = $project;
        $this->avancement = (float) $project->avancement_pct;
        $this->tauxRealisation = (float) $project->taux_realisation_pct;
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

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function compteurs(): array
    {
        $livrables = $this->project->deliverables;
        $taches = $this->project->tasks;

        return [
            'livrables_total' => $livrables->count(),
            'livrables_disponibles' => $livrables->filter(fn ($l) => $l->estDisponible())->count(),
            'livrables_echeances' => Echeances::de($livrables),
            'taches_ouvertes' => $taches->filter(fn ($t) => ! $t->statut->isClosed())->count(),
            'taches_echeances' => Echeances::de($taches),
            'jalons_echeances' => Echeances::de($this->project->milestones),
            'ressources' => $this->project->members()->distinct('user_id')->count('user_id'),
        ];
    }

    /**
     * Avance le bandeau d'avancement à l'étape choisie.
     */
    public function positionnerEtape(int $projectStepId): void
    {
        $this->authorize('update', $this->project);

        $cible = $this->project->steps()->with('workflowStep')->findOrFail($projectStepId);

        foreach ($this->project->steps as $etape) {
            $etape->update([
                'statut' => match (true) {
                    $etape->ordre < $cible->ordre => ProgressStatus::Termine,
                    $etape->ordre === $cible->ordre => ProgressStatus::EnCours,
                    default => ProgressStatus::NonDemarre,
                },
                'date_reelle' => $etape->ordre <= $cible->ordre ? ($etape->date_reelle ?? now()) : null,
            ]);
        }

        $this->project->update([
            'workflow_step_id' => $cible->workflow_step_id,
            'phase_id' => $cible->workflowStep?->phase_id,
        ]);

        $this->project->refresh()->load('steps.workflowStep');

        Flux::toast(variant: 'success', text: 'Étape courante mise à jour.');
    }

    public function enregistrerAvancement(): void
    {
        $this->authorize('update', $this->project);

        $this->validate([
            'avancement' => ['required', 'numeric', 'min:0', 'max:100'],
            'tauxRealisation' => ['required', 'numeric', 'min:0', 'max:100'],
        ], attributes: [
            'avancement' => 'avancement',
            'tauxRealisation' => 'taux de réalisation',
        ]);

        $this->project->update([
            'avancement_pct' => $this->avancement,
            'taux_realisation_pct' => $this->tauxRealisation,
        ]);

        unset($this->sante);

        Flux::toast(variant: 'success', text: 'Avancement enregistré.');
    }
}; ?>

<div class="grid gap-6 xl:grid-cols-3">
    <div class="flex flex-col gap-6 xl:col-span-2">
        {{-- Bandeau d'avancement --}}
        <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between gap-3">
                <flux:heading size="lg">Phase actuelle du projet</flux:heading>
                <flux:text size="sm">{{ $project->phase?->nom }}</flux:text>
            </div>

            <ol class="mt-3 flex flex-wrap gap-1.5">
                @foreach ($project->steps as $etape)
                    @php
                        $classes = match ($etape->statut) {
                            ProgressStatus::Termine => 'border-green-500/40 bg-green-50 text-green-800 dark:bg-green-950/60 dark:text-green-200',
                            ProgressStatus::EnCours => 'border-blue-500 bg-blue-600 text-white',
                            ProgressStatus::Bloque => 'border-red-500/40 bg-red-50 text-red-800 dark:bg-red-950/60 dark:text-red-200',
                            default => 'border-zinc-200 bg-white text-zinc-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-400',
                        };
                    @endphp

                    <li>
                        <button
                            type="button"
                            @if ($this->peutModifier()) wire:click="positionnerEtape({{ $etape->id }})" @else disabled @endif
                            class="flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs {{ $classes }} @if ($this->peutModifier()) cursor-pointer transition hover:opacity-80 @endif"
                            title="{{ $this->peutModifier() ? 'Marquer comme étape courante' : $etape->statut->label() }}"
                        >
                            @if ($etape->statut === ProgressStatus::Termine)
                                <flux:icon name="check" variant="micro" />
                            @endif
                            <span class="font-medium">{{ $etape->workflowStep?->libelle }}</span>
                            @if ($etape->annotation)
                                <span class="opacity-75">{{ $etape->annotation }}</span>
                            @endif
                        </button>
                    </li>
                @endforeach
            </ol>
        </section>

        {{-- Planning & avancement --}}
        <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg">Planning &amp; avancement</flux:heading>

            <div class="mt-3 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">Date début</div>
                    <div class="font-semibold tabular-nums">{{ $project->date_debut?->format('d/m/Y') ?? '—' }}</div>
                </div>
                <div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">Date fin initiale</div>
                    <div class="font-semibold tabular-nums">{{ $project->date_fin_initiale?->format('d/m/Y') ?? '—' }}</div>
                </div>
                <div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">Date fin révisée</div>
                    <div class="font-semibold tabular-nums">{{ $project->date_fin_revisee?->format('d/m/Y') ?? '—' }}</div>
                </div>
                <div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">Glissement</div>
                    <div @class([
                        'font-semibold tabular-nums',
                        'text-red-600 dark:text-red-400' => $project->glissementJours() > 0,
                    ])>
                        {{ $project->glissementJours() > 0 ? '+'.$project->glissementJours().' j' : '—' }}
                    </div>
                </div>
            </div>

            <div class="mt-4 space-y-3">
                <div>
                    <div class="mb-1 flex items-center justify-between text-xs text-zinc-500 dark:text-zinc-400">
                        <span>Avancement déclaré</span>
                        @if ($this->sante()->avancementTheoriquePct !== null)
                            <span>Attendu à date : {{ number_format($this->sante()->avancementTheoriquePct, 1, ',', ' ') }} %</span>
                        @endif
                    </div>
                    <x-progress-bar
                        :value="$project->avancement_pct"
                        :target="$this->sante()->avancementTheoriquePct"
                        color="green"
                    />
                </div>

                <div>
                    <div class="mb-1 text-xs text-zinc-500 dark:text-zinc-400">Taux de réalisation</div>
                    <x-progress-bar :value="$project->taux_realisation_pct" color="violet" />
                </div>
            </div>

            @if ($this->peutModifier())
                <form wire:submit="enregistrerAvancement" class="mt-4 flex flex-wrap items-end gap-3 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                    <flux:input wire:model="avancement" type="number" step="0.01" min="0" max="100" label="% Avancement" class="w-36" />
                    <flux:input wire:model="tauxRealisation" type="number" step="0.01" min="0" max="100" label="Taux de réalisation" class="w-40" />
                    <flux:button type="submit" variant="primary" size="sm">Enregistrer</flux:button>
                </form>
            @endif
        </section>

        {{-- Compteurs opérationnels --}}
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @php
                $livrablesEcheances = $this->compteurs()['livrables_echeances'];
                $tachesEcheances = $this->compteurs()['taches_echeances'];
                $jalonsEcheances = $this->compteurs()['jalons_echeances'];
            @endphp

            {{-- Chaque mention de retard dit sur quoi elle se fonde : un projet
                 sans échéance saisie n'est pas un projet sans retard. --}}
            <x-stat
                label="Livrables disponibles"
                :value="$this->compteurs()['livrables_disponibles'].' / '.$this->compteurs()['livrables_total']"
                :sub="$livrablesEcheances->mesurable() ? $livrablesEcheances->enRetard.' en retard' : 'Retards non mesurables'"
                icon="document-text"
                color="violet"
            />
            <x-stat
                label="Tâches ouvertes"
                :value="$this->compteurs()['taches_ouvertes']"
                :sub="$tachesEcheances->mesurable() ? $tachesEcheances->enRetard.' en retard' : 'Retards non mesurables'"
                icon="check-circle"
                color="blue"
            />
            <x-stat
                label="Jalons en retard"
                :value="$jalonsEcheances->valeur()"
                :sub="$jalonsEcheances->precision()"
                icon="flag"
                :color="$jalonsEcheances->couleur()"
            />
            <x-stat label="Ressources affectées" :value="$this->compteurs()['ressources']" icon="users" color="green" />
        </div>
    </div>

    {{-- Colonne latérale --}}
    <div class="flex flex-col gap-6">
        <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg">Pilotage</flux:heading>

            <dl class="mt-3 space-y-2 text-sm">
                @foreach ([
                    'Client' => $project->client->displayName(),
                    'Type projet' => $project->type_projet->label(),
                    'Pays de domiciliation' => $project->pays_domiciliation,
                    MemberRole::ChefProjet->label() => $project->chefProjet?->name,
                    MemberRole::BackUp->label() => $project->backUp?->name,
                    MemberRole::Sponsor->label() => $project->sponsor?->name,
                    MemberRole::ReferentTechnique->label() => $project->referentTechnique?->name,
                ] as $label => $valeur)
                    <div class="flex justify-between gap-3">
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                        <dd class="text-end font-medium">{{ $valeur ?? '—' }}</dd>
                    </div>
                @endforeach
            </dl>

            @if ($project->lien_planning)
                <flux:separator class="my-3" />
                <flux:link :href="$project->lien_planning" target="_blank" rel="noopener" class="text-sm">
                    Ouvrir le planning détaillé
                </flux:link>
            @endif
        </section>

        <section>
            <flux:heading size="lg" class="mb-3">Points d'attention</flux:heading>

            @if ($this->sante()->alertes->isEmpty())
                <flux:callout icon="check-circle" color="green" heading="Aucune dérive détectée" />
            @else
                <ul class="space-y-2">
                    @foreach ($this->sante()->alertes as $alerte)
                        <li @class([
                            'rounded-lg border p-3 text-sm',
                            'border-red-200 bg-red-50 dark:border-red-900/60 dark:bg-red-950/40' => $alerte->severite->color() === 'red',
                            'border-amber-200 bg-amber-50 dark:border-amber-900/60 dark:bg-amber-950/40' => $alerte->severite->color() === 'amber',
                        ])>
                            <p class="font-medium text-zinc-900 dark:text-white">{{ $alerte->libelle }}</p>
                            @if ($alerte->detail)
                                <p class="mt-0.5 text-xs text-zinc-600 dark:text-zinc-400">{{ $alerte->detail }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</div>
