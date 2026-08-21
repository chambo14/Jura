<?php

use App\Enums\GovernanceBody;
use App\Enums\ProgressStatus;
use App\Models\Milestone;
use App\Models\Project;
use App\Services\GanttBuilder;
use App\Support\Gantt\GanttChart;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Project $project;

    /** @var array<int, array<string, mixed>> */
    public array $phases = [];

    public ?int $jalonEnEdition = null;

    public string $jalonLibelle = '';

    public string $jalonDate = '';

    public string $jalonStatut = ProgressStatus::NonDemarre->value;

    public string $jalonInstance = '';

    public bool $jalonCritique = false;

    /** Délais du projet : c'est ici que se constate la dérive, donc ici qu'on l'ajuste. */
    public string $dateDebut = '';

    public string $dateFinInitiale = '';

    public string $dateFinRevisee = '';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);

        $this->project = $project;
        $this->chargerPhases();
        $this->chargerDelais();
    }

    private function chargerDelais(): void
    {
        $this->dateDebut = $this->project->date_debut?->format('Y-m-d') ?? '';
        $this->dateFinInitiale = $this->project->date_fin_initiale?->format('Y-m-d') ?? '';
        $this->dateFinRevisee = $this->project->date_fin_revisee?->format('Y-m-d') ?? '';
    }

    /**
     * La date de fin initiale reste l'engagement d'origine : seule la date révisée
     * bouge au fil des reports, et c'est l'écart entre les deux qui mesure la dérive.
     */
    public function enregistrerDelais(): void
    {
        $this->authorize('update', $this->project);

        $donnees = $this->validate([
            'dateDebut' => ['nullable', 'date'],
            'dateFinInitiale' => ['nullable', 'date', 'after_or_equal:dateDebut'],
            'dateFinRevisee' => ['nullable', 'date', 'after_or_equal:dateDebut'],
        ], attributes: [
            'dateDebut' => 'date de début',
            'dateFinInitiale' => 'date de fin initiale',
            'dateFinRevisee' => 'date de fin révisée',
        ]);

        $this->project->update([
            'date_debut' => $donnees['dateDebut'] ?: null,
            'date_fin_initiale' => $donnees['dateFinInitiale'] ?: null,
            'date_fin_revisee' => $donnees['dateFinRevisee'] ?: null,
        ]);

        $this->project->refresh();
        $this->chargerDelais();
        unset($this->gantt);

        $glissement = $this->project->glissementJours();

        Flux::toast(
            variant: 'success',
            text: $glissement > 0
                ? "Délais enregistrés — glissement de {$glissement} jours sur la date de fin."
                : 'Délais enregistrés.',
        );
    }

    private function chargerPhases(): void
    {
        $this->phases = $this->project->phases()->with('phase')->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'nom' => $p->phase->nom,
                'debut' => $p->date_debut_prevue?->format('Y-m-d') ?? '',
                'fin' => $p->date_fin_prevue?->format('Y-m-d') ?? '',
                'debut_reel' => $p->date_debut_reelle?->format('Y-m-d') ?? '',
                'fin_reelle' => $p->date_fin_reelle?->format('Y-m-d') ?? '',
                'avancement' => (float) $p->avancement_pct,
                'statut' => $p->statut->value,
            ])
            ->all();
    }

    #[Computed]
    public function peutModifier(): bool
    {
        return auth()->user()->can('update', $this->project);
    }

    #[Computed]
    public function gantt(): GanttChart
    {
        return app(GanttBuilder::class)->construire($this->project);
    }

    public function enregistrerPhases(): void
    {
        $this->authorize('update', $this->project);

        $this->validate([
            'phases.*.debut' => ['nullable', 'date'],
            'phases.*.fin' => ['nullable', 'date', 'after_or_equal:phases.*.debut'],
            'phases.*.debut_reel' => ['nullable', 'date'],
            'phases.*.fin_reelle' => ['nullable', 'date', 'after_or_equal:phases.*.debut_reel'],
            'phases.*.avancement' => ['required', 'numeric', 'min:0', 'max:100'],
            'phases.*.statut' => ['required', 'string'],
        ]);

        foreach ($this->phases as $ligne) {
            $this->project->phases()->whereKey($ligne['id'])->update([
                'date_debut_prevue' => $ligne['debut'] ?: null,
                'date_fin_prevue' => $ligne['fin'] ?: null,
                'date_debut_reelle' => $ligne['debut_reel'] ?: null,
                'date_fin_reelle' => $ligne['fin_reelle'] ?: null,
                'avancement_pct' => $ligne['avancement'],
                'statut' => $ligne['statut'],
            ]);
        }

        $this->project->load('phases.phase');
        unset($this->gantt);

        Flux::toast(variant: 'success', text: 'Planning des phases enregistré.');
    }

    public function nouveauJalon(): void
    {
        $this->authorize('update', $this->project);

        $this->reset('jalonEnEdition', 'jalonLibelle', 'jalonDate', 'jalonInstance', 'jalonCritique');
        $this->jalonStatut = ProgressStatus::NonDemarre->value;

        Flux::modal('jalon')->show();
    }

    public function editerJalon(int $id): void
    {
        $this->authorize('update', $this->project);

        $jalon = $this->project->milestones()->findOrFail($id);

        $this->jalonEnEdition = $jalon->id;
        $this->jalonLibelle = $jalon->libelle;
        $this->jalonDate = $jalon->date_prevue->format('Y-m-d');
        $this->jalonStatut = $jalon->statut->value;
        $this->jalonInstance = $jalon->instance?->value ?? '';
        $this->jalonCritique = $jalon->critique;

        Flux::modal('jalon')->show();
    }

    public function enregistrerJalon(): void
    {
        $this->authorize('update', $this->project);

        $donnees = $this->validate([
            'jalonLibelle' => ['required', 'string', 'max:255'],
            'jalonDate' => ['required', 'date'],
            'jalonStatut' => ['required', 'string'],
            'jalonInstance' => ['nullable', 'string'],
            'jalonCritique' => ['boolean'],
        ], attributes: [
            'jalonLibelle' => 'libellé',
            'jalonDate' => 'date prévue',
        ]);

        $attributs = [
            'libelle' => $donnees['jalonLibelle'],
            'date_prevue' => $donnees['jalonDate'],
            'statut' => $donnees['jalonStatut'],
            'instance' => $donnees['jalonInstance'] ?: null,
            'critique' => $donnees['jalonCritique'],
            'date_reelle' => $donnees['jalonStatut'] === ProgressStatus::Termine->value ? now() : null,
        ];

        if ($this->jalonEnEdition) {
            $jalon = $this->project->milestones()->findOrFail($this->jalonEnEdition);
            // Conserve la première date annoncée pour mesurer le glissement.
            $attributs['date_prevue_initiale'] = $jalon->date_prevue_initiale ?? $jalon->date_prevue;
            $jalon->update($attributs);
        } else {
            $attributs['date_prevue_initiale'] = $donnees['jalonDate'];
            $this->project->milestones()->create($attributs);
        }

        $this->project->load('milestones');
        unset($this->gantt);

        Flux::modal('jalon')->close();
        Flux::toast(variant: 'success', text: 'Jalon enregistré.');
    }

    public function supprimerJalon(int $id): void
    {
        $this->authorize('update', $this->project);

        $this->project->milestones()->whereKey($id)->delete();
        $this->project->load('milestones');
        unset($this->gantt);

        Flux::toast(variant: 'success', text: 'Jalon supprimé.');
    }

    /**
     * @return \Illuminate\Support\Collection<int, Milestone>
     */
    #[Computed]
    public function jalons(): \Illuminate\Support\Collection
    {
        return $this->project->milestones()->orderBy('date_prevue')->get();
    }
}; ?>

<div class="flex flex-col gap-6">
    {{-- Délais du projet --}}
    <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <flux:heading size="lg">Délais du projet</flux:heading>
                <flux:subheading>
                    La date de fin initiale est l'engagement d'origine ; seule la date révisée
                    est reportée au fil des arbitrages.
                </flux:subheading>
            </div>

            @php $glissement = $project->glissementJours(); @endphp

            <div class="text-end">
                <div class="text-xs text-zinc-500 dark:text-zinc-400">Glissement</div>
                <div @class([
                    'text-lg font-semibold tabular-nums',
                    'text-red-600 dark:text-red-400' => $glissement > 0,
                    'text-green-600 dark:text-green-400' => $glissement < 0,
                    'text-zinc-500 dark:text-zinc-400' => $glissement === 0,
                ])>
                    {{ $glissement > 0 ? '+'.$glissement.' j' : ($glissement < 0 ? $glissement.' j' : 'aucun') }}
                </div>
            </div>
        </div>

        <form wire:submit="enregistrerDelais" class="mt-4 flex flex-wrap items-end gap-3">
            <flux:input
                wire:model="dateDebut"
                type="date"
                label="Date de début"
                class="w-44"
                :disabled="! $this->peutModifier()"
            />
            <flux:input
                wire:model="dateFinInitiale"
                type="date"
                label="Date de fin initiale"
                class="w-44"
                :disabled="! $this->peutModifier()"
            />
            <flux:input
                wire:model="dateFinRevisee"
                type="date"
                label="Date de fin révisée"
                class="w-44"
                :disabled="! $this->peutModifier()"
                description="Laisser vide si le planning n'a pas été revu"
            />

            @if ($this->peutModifier())
                <flux:button type="submit" variant="primary" icon="check" class="mb-0.5">
                    Enregistrer les délais
                </flux:button>
            @endif
        </form>

        @php $restants = $project->joursRestants(); @endphp

        @if ($restants !== null)
            <p class="mt-3 border-t border-zinc-100 pt-3 text-sm dark:border-zinc-800">
                Échéance qui fait foi :
                <span class="font-medium tabular-nums">{{ $project->dateFinEffective()?->format('d/m/Y') }}</span>
                @if ($restants < 0)
                    · <span class="font-medium text-red-600 dark:text-red-400">dépassée de {{ abs($restants) }} jours</span>
                @else
                    · <span class="text-zinc-600 dark:text-zinc-300">{{ $restants }} jours restants</span>
                @endif
            </p>
        @else
            <p class="mt-3 border-t border-zinc-100 pt-3 text-sm text-amber-600 dark:border-zinc-800 dark:text-amber-400">
                Aucune date de fin renseignée : le projet remonte en alerte tant qu'elle n'est pas arrêtée.
            </p>
        @endif
    </section>

    {{-- Diagramme de Gantt --}}
    <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:heading size="lg">Diagramme de Gantt</flux:heading>
        <flux:subheading>
            {{ $this->gantt()->debut->translatedFormat('F Y') }} → {{ $this->gantt()->fin->translatedFormat('F Y') }}
        </flux:subheading>

        <x-gantt-chart :gantt="$this->gantt()" class="mt-4" />
    </section>

    {{-- Phases MPM --}}
    <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <flux:heading size="lg">Phases MPM</flux:heading>
                <flux:subheading>Dates prévues et réelles des 8 phases de la méthodologie</flux:subheading>
            </div>

            @if ($this->peutModifier())
                <flux:button wire:click="enregistrerPhases" variant="primary" size="sm" icon="check">
                    Enregistrer le planning
                </flux:button>
            @endif
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="w-full min-w-[56rem] text-sm">
                <thead class="text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                    <tr class="border-b border-zinc-200 dark:border-zinc-700">
                        <th class="py-2 pe-3 text-start font-medium">Phase</th>
                        <th class="px-2 py-2 text-start font-medium">Début prévu</th>
                        <th class="px-2 py-2 text-start font-medium">Fin prévue</th>
                        <th class="px-2 py-2 text-start font-medium">Début réel</th>
                        <th class="px-2 py-2 text-start font-medium">Fin réelle</th>
                        <th class="px-2 py-2 text-start font-medium">%</th>
                        <th class="ps-2 py-2 text-start font-medium">Statut</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($phases as $index => $ligne)
                        <tr wire:key="phase-{{ $ligne['id'] }}">
                            <td class="py-2 pe-3 font-medium">{{ $ligne['nom'] }}</td>
                            <td class="px-2 py-2">
                                <flux:input wire:model="phases.{{ $index }}.debut" type="date" size="sm" :disabled="! $this->peutModifier()" />
                            </td>
                            <td class="px-2 py-2">
                                <flux:input wire:model="phases.{{ $index }}.fin" type="date" size="sm" :disabled="! $this->peutModifier()" />
                            </td>
                            <td class="px-2 py-2">
                                <flux:input wire:model="phases.{{ $index }}.debut_reel" type="date" size="sm" :disabled="! $this->peutModifier()" />
                            </td>
                            <td class="px-2 py-2">
                                <flux:input wire:model="phases.{{ $index }}.fin_reelle" type="date" size="sm" :disabled="! $this->peutModifier()" />
                            </td>
                            <td class="px-2 py-2">
                                <flux:input wire:model="phases.{{ $index }}.avancement" type="number" min="0" max="100" size="sm" class="w-20" :disabled="! $this->peutModifier()" />
                            </td>
                            <td class="ps-2 py-2">
                                <flux:select wire:model="phases.{{ $index }}.statut" size="sm" :disabled="! $this->peutModifier()">
                                    @foreach (ProgressStatus::cases() as $cas)
                                        <flux:select.option :value="$cas->value">{{ $cas->label() }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    {{-- Jalons --}}
    <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <flux:heading size="lg">Jalons</flux:heading>
                <flux:subheading>Échéances contractuelles et instances de gouvernance</flux:subheading>
            </div>

            @if ($this->peutModifier())
                <flux:button wire:click="nouveauJalon" variant="filled" size="sm" icon="plus">Ajouter un jalon</flux:button>
            @endif
        </div>

        <div class="mt-4 divide-y divide-zinc-100 dark:divide-zinc-800">
            @forelse ($this->jalons() as $jalon)
                <div class="flex flex-wrap items-center gap-3 py-2.5" wire:key="jalon-{{ $jalon->id }}">
                    <div @class([
                        'size-2.5 shrink-0 rotate-45',
                        'bg-red-500' => $jalon->enRetard(),
                        'bg-green-500' => ! $jalon->enRetard() && $jalon->statut === ProgressStatus::Termine,
                        'bg-zinc-400' => ! $jalon->enRetard() && $jalon->statut !== ProgressStatus::Termine,
                    ])></div>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium">{{ $jalon->libelle }}</span>
                            @if ($jalon->critique)
                                <flux:badge color="red" size="sm">Critique</flux:badge>
                            @endif
                            @if ($jalon->instance)
                                <flux:badge color="zinc" size="sm">{{ $jalon->instance->acronym() }}</flux:badge>
                            @endif
                        </div>
                        @if ($jalon->glissementJours() > 0)
                            <div class="text-xs text-amber-600 dark:text-amber-400">
                                Reporté de {{ $jalon->glissementJours() }} j (initialement le {{ $jalon->date_prevue_initiale->format('d/m/Y') }})
                            </div>
                        @endif
                    </div>

                    <div class="text-sm tabular-nums text-zinc-600 dark:text-zinc-300">{{ $jalon->date_prevue->format('d/m/Y') }}</div>

                    <flux:badge :color="$jalon->statut->color()" size="sm">{{ $jalon->statut->label() }}</flux:badge>

                    @if ($this->peutModifier())
                        <flux:button wire:click="editerJalon({{ $jalon->id }})" icon="pencil-square" variant="ghost" size="xs" inset />
                        <flux:button
                            wire:click="supprimerJalon({{ $jalon->id }})"
                            wire:confirm="Supprimer ce jalon ?"
                            icon="trash"
                            variant="ghost"
                            size="xs"
                            inset
                        />
                    @endif
                </div>
            @empty
                <flux:text class="py-6 text-center">Aucun jalon défini pour ce projet.</flux:text>
            @endforelse
        </div>
    </section>

    {{-- Modale jalon --}}
    <flux:modal name="jalon" class="md:w-96">
        <form wire:submit="enregistrerJalon" class="space-y-5">
            <flux:heading size="lg">{{ $jalonEnEdition ? 'Modifier le jalon' : 'Nouveau jalon' }}</flux:heading>

            <flux:input wire:model="jalonLibelle" label="Libellé" required />
            <flux:input wire:model="jalonDate" type="date" label="Date prévue" required />

            <flux:select wire:model="jalonStatut" label="Statut">
                @foreach (ProgressStatus::cases() as $cas)
                    <flux:select.option :value="$cas->value">{{ $cas->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="jalonInstance" label="Instance de gouvernance" placeholder="Aucune">
                <flux:select.option value="">Aucune</flux:select.option>
                @foreach (GovernanceBody::cases() as $cas)
                    <flux:select.option :value="$cas->value">{{ $cas->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:checkbox wire:model="jalonCritique" label="Jalon critique" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Annuler</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Enregistrer</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
