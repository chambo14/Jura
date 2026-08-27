<?php

use App\Enums\ProgressStatus;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\Task;
use App\Models\User;
use App\Services\ChargeService;
use App\Support\Echeances\Echeances;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Project $project;

    public string $filtreStatut = '';

    public string $filtreAssignee = '';

    public bool $seulementRetard = false;

    public ?int $tacheEnEdition = null;

    public string $tacheLibelle = '';

    public string $tacheStatut = ProgressStatus::NonDemarre->value;

    public string $tacheAssignee = '';

    public string $tachePhase = '';

    public string $tacheDebut = '';

    public string $tacheEcheance = '';

    public string $tacheRealisation = '';

    public int $tacheAvancement = 0;

    public string $tacheDescription = '';

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

    /**
     * @return Collection<int, Task>
     */
    #[Computed]
    public function taches(): Collection
    {
        return $this->project->tasks()
            ->with(['assignee', 'projectPhase.phase'])
            ->when($this->filtreStatut !== '', fn ($q) => $q->where('statut', $this->filtreStatut))
            ->when($this->filtreAssignee !== '', fn ($q) => $q->where('assignee_id', $this->filtreAssignee))
            ->orderByRaw('date_echeance IS NULL')
            ->orderBy('date_echeance')
            ->get()
            ->when($this->seulementRetard, fn (Collection $taches) => $taches->filter(fn (Task $t) => $t->enRetard()))
            ->values();
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function synthese(): array
    {
        $toutes = $this->project->tasks;

        return [
            'total' => $toutes->count(),
            'terminees' => $toutes->where('statut', ProgressStatus::Termine)->count(),
            'en_cours' => $toutes->where('statut', ProgressStatus::EnCours)->count(),
            'echeances' => Echeances::de($toutes),
        ];
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function intervenants(): Collection
    {
        return User::query()
            ->whereIn('id', $this->project->members()->select('user_id'))
            ->orWhereIn('id', $this->project->tasks()->whereNotNull('assignee_id')->select('assignee_id'))
            ->orderBy('name')
            ->get();
    }

    /**
     * Toute personne active peut recevoir une tâche : c'est ainsi qu'un référent
     * technique confie du travail à quelqu'un de son équipe, extérieur au projet.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function collaborateurs(): Collection
    {
        return User::actifs()->orderBy('name')->get();
    }

    /**
     * @return Collection<int, ProjectPhase>
     */
    #[Computed]
    public function phasesProjet(): Collection
    {
        return $this->project->phases()->with('phase')->get();
    }

    public function nouvelleTache(): void
    {
        $this->authorize('contribute', $this->project);

        $this->reset(
            'tacheEnEdition', 'tacheLibelle', 'tacheAssignee', 'tacheDebut',
            'tacheEcheance', 'tacheRealisation', 'tacheDescription',
        );
        $this->tacheStatut = ProgressStatus::NonDemarre->value;
        $this->tacheAvancement = 0;
        $this->tachePhase = (string) ($this->phasesProjet()
            ->firstWhere('phase_id', $this->project->phase_id)?->id ?? '');

        Flux::modal('tache')->show();
    }

    public function editerTache(int $id): void
    {
        $this->authorize('contribute', $this->project);

        $tache = $this->project->tasks()->findOrFail($id);

        $this->tacheEnEdition = $tache->id;
        $this->tacheLibelle = $tache->libelle;
        $this->tacheStatut = $tache->statut->value;
        $this->tacheAssignee = (string) ($tache->assignee_id ?? '');
        $this->tachePhase = (string) ($tache->project_phase_id ?? '');
        $this->tacheDebut = $tache->date_debut?->format('Y-m-d') ?? '';
        $this->tacheEcheance = $tache->date_echeance?->format('Y-m-d') ?? '';
        $this->tacheRealisation = $tache->date_realisation?->format('Y-m-d') ?? '';
        $this->tacheAvancement = (int) $tache->avancement_pct;
        $this->tacheDescription = $tache->description ?? '';

        Flux::modal('tache')->show();
    }

    public function enregistrerTache(): void
    {
        $this->authorize('contribute', $this->project);

        $donnees = $this->validate([
            'tacheLibelle' => ['required', 'string', 'max:500'],
            'tacheStatut' => ['required', 'string'],
            'tacheAssignee' => ['nullable', 'exists:users,id'],
            'tachePhase' => ['nullable', 'exists:project_phases,id'],
            'tacheDebut' => ['nullable', 'date'],
            'tacheEcheance' => ['nullable', 'date', 'after_or_equal:tacheDebut'],
            'tacheRealisation' => ['nullable', 'date'],
            'tacheAvancement' => ['required', 'integer', 'min:0', 'max:100'],
            'tacheDescription' => ['nullable', 'string', 'max:2000'],
        ], attributes: [
            'tacheLibelle' => 'libellé',
            'tacheEcheance' => 'échéance',
        ]);

        $statut = ProgressStatus::from($donnees['tacheStatut']);

        $attributs = [
            'libelle' => $donnees['tacheLibelle'],
            'statut' => $statut,
            'assignee_id' => $donnees['tacheAssignee'] ?: null,
            'project_phase_id' => $donnees['tachePhase'] ?: null,
            'date_debut' => $donnees['tacheDebut'] ?: null,
            'date_echeance' => $donnees['tacheEcheance'] ?: null,
            // Une tâche terminée sans date de réalisation est datée du jour.
            'date_realisation' => $donnees['tacheRealisation']
                ?: ($statut === ProgressStatus::Termine ? now()->toDateString() : null),
            'avancement_pct' => $statut === ProgressStatus::Termine ? 100 : $donnees['tacheAvancement'],
            'description' => $donnees['tacheDescription'] ?: null,
        ];

        // Confier une tâche à quelqu'un d'autre, c'est déléguer : on trace qui.
        if ($attributs['assignee_id'] && (int) $attributs['assignee_id'] !== auth()->id()) {
            $attributs['delegue_par_id'] = auth()->id();
        }

        if ($this->tacheEnEdition) {
            $tache = $this->project->tasks()->findOrFail($this->tacheEnEdition);
            $tache->update($attributs);
        } else {
            $tache = $this->project->tasks()->create($attributs);
        }

        // Sans rattachement, le porteur ne verrait pas le projet et n'apparaîtrait
        // dans aucun plan de charge.
        $rattache = app(ChargeService::class)->rattacherParDelegation($tache);

        $this->project->load(['tasks', 'members.user']);
        unset($this->taches, $this->synthese, $this->intervenants);

        Flux::modal('tache')->close();
        Flux::toast(
            variant: 'success',
            text: $rattache
                ? 'Tâche enregistrée — '.$rattache->user->name.' a été rattaché au projet comme contributeur.'
                : 'Tâche enregistrée.',
        );
    }

    /**
     * Bascule rapide « terminée / à reprendre » depuis la liste.
     */
    public function basculerTache(int $id): void
    {
        $this->authorize('contribute', $this->project);

        $tache = $this->project->tasks()->findOrFail($id);
        $termine = $tache->statut === ProgressStatus::Termine;

        $tache->update([
            'statut' => $termine ? ProgressStatus::EnCours : ProgressStatus::Termine,
            'date_realisation' => $termine ? null : now(),
            'avancement_pct' => $termine ? 50 : 100,
        ]);

        $this->project->load('tasks');
        unset($this->taches, $this->synthese);
    }

    public function supprimerTache(int $id): void
    {
        $this->authorize('contribute', $this->project);

        $this->project->tasks()->whereKey($id)->delete();
        $this->project->load('tasks');
        unset($this->taches, $this->synthese);

        Flux::toast(variant: 'success', text: 'Tâche supprimée.');
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat label="Tâches" :value="$this->synthese()['total']" icon="check-circle" color="blue" />
        <x-stat label="Terminées" :value="$this->synthese()['terminees']" icon="check" color="green" />
        <x-stat label="En cours" :value="$this->synthese()['en_cours']" icon="play" color="violet" />
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
            <flux:select wire:model.live="filtreStatut" label="Statut" class="w-44">
                <flux:select.option value="">Tous</flux:select.option>
                @foreach (ProgressStatus::cases() as $cas)
                    <flux:select.option :value="$cas->value">{{ $cas->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="filtreAssignee" label="Assigné à" class="w-52">
                <flux:select.option value="">Tous</flux:select.option>
                @foreach ($this->intervenants() as $intervenant)
                    <flux:select.option :value="$intervenant->id">{{ $intervenant->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:checkbox wire:model.live="seulementRetard" label="En retard uniquement" class="pb-2" />
        </div>

        @if ($this->peutContribuer())
            <flux:button wire:click="nouvelleTache" variant="primary" size="sm" icon="plus">Ajouter une tâche</flux:button>
        @endif
    </div>

    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800/60 dark:text-zinc-400">
                <tr>
                    <th class="w-10 px-4 py-2"></th>
                    <th class="px-4 py-2 text-start font-medium">Tâche</th>
                    <th class="px-4 py-2 text-start font-medium">Phase</th>
                    <th class="px-4 py-2 text-start font-medium">Assigné à</th>
                    <th class="px-4 py-2 text-start font-medium">Échéance</th>
                    <th class="px-4 py-2 text-start font-medium">Statut</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                @forelse ($this->taches() as $tache)
                    <tr wire:key="tache-{{ $tache->id }}" @class(['bg-red-50/50 dark:bg-red-950/20' => $tache->enRetard()])>
                        <td class="px-4 py-3">
                            <flux:checkbox
                                :checked="$tache->statut === App\Enums\ProgressStatus::Termine"
                                wire:click="basculerTache({{ $tache->id }})"
                                :disabled="! $this->peutContribuer()"
                            />
                        </td>

                        <td class="px-4 py-3">
                            <div @class([
                                'font-medium',
                                'text-zinc-400 line-through dark:text-zinc-500' => $tache->statut === App\Enums\ProgressStatus::Termine,
                            ])>{{ $tache->libelle }}</div>
                            @if ($tache->avancement_pct > 0 && $tache->avancement_pct < 100)
                                <x-progress-bar class="mt-1 max-w-40" :value="$tache->avancement_pct" color="blue" />
                            @endif
                        </td>

                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">
                            {{ $tache->projectPhase?->phase->nom ?? '—' }}
                        </td>

                        <td class="px-4 py-3">
                            @if ($tache->assignee)
                                <div class="flex items-center gap-2">
                                    <flux:avatar :name="$tache->assignee->name" size="xs" />
                                    <span class="truncate">{{ $tache->assignee->name }}</span>
                                </div>
                            @else
                                <span class="text-zinc-400">—</span>
                            @endif
                        </td>

                        <td class="px-4 py-3 whitespace-nowrap">
                            <span @class([
                                'tabular-nums',
                                'font-medium text-red-600 dark:text-red-400' => $tache->enRetard(),
                                'text-zinc-600 dark:text-zinc-300' => ! $tache->enRetard(),
                            ])>{{ $tache->date_echeance?->format('d/m/Y') ?? '—' }}</span>
                        </td>

                        <td class="px-4 py-3">
                            <flux:badge :color="$tache->statut->color()" size="sm">{{ $tache->statut->label() }}</flux:badge>
                        </td>

                        <td class="px-4 py-3 text-end">
                            @if ($this->peutContribuer())
                                <flux:button wire:click="editerTache({{ $tache->id }})" icon="pencil-square" variant="ghost" size="xs" inset />
                                <flux:button
                                    wire:click="supprimerTache({{ $tache->id }})"
                                    wire:confirm="Supprimer cette tâche ?"
                                    icon="trash"
                                    variant="ghost"
                                    size="xs"
                                    inset
                                />
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-zinc-500 dark:text-zinc-400">
                            Aucune tâche ne correspond à ces critères.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <flux:modal name="tache" class="md:w-[32rem]">
        <form wire:submit="enregistrerTache" class="space-y-5">
            <flux:heading size="lg">{{ $tacheEnEdition ? 'Modifier la tâche' : 'Nouvelle tâche' }}</flux:heading>

            <flux:input wire:model="tacheLibelle" label="Libellé" required />

            <div class="grid grid-cols-2 gap-3">
                <flux:select wire:model="tachePhase" label="Phase MPM" placeholder="Aucune">
                    <flux:select.option value="">Aucune</flux:select.option>
                    @foreach ($this->phasesProjet() as $phase)
                        <flux:select.option :value="$phase->id">{{ $phase->phase->nom }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select
                    wire:model="tacheAssignee"
                    label="Assigné à"
                    placeholder="Non assigné"
                    description="Un collaborateur hors projet sera rattaché comme contributeur"
                >
                    <flux:select.option value="">Non assigné</flux:select.option>
                    @foreach ($this->collaborateurs() as $collaborateur)
                        <flux:select.option :value="$collaborateur->id">{{ $collaborateur->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="grid grid-cols-3 gap-3">
                <flux:input wire:model="tacheDebut" type="date" label="Début" />
                <flux:input wire:model="tacheEcheance" type="date" label="Échéance" />
                <flux:input wire:model="tacheRealisation" type="date" label="Réalisation" />
            </div>

            <div class="grid grid-cols-2 gap-3">
                <flux:select wire:model="tacheStatut" label="Statut">
                    @foreach (ProgressStatus::cases() as $cas)
                        <flux:select.option :value="$cas->value">{{ $cas->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="tacheAvancement" type="number" min="0" max="100" label="Avancement (%)" />
            </div>

            <flux:textarea wire:model="tacheDescription" label="Description" rows="2" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Annuler</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Enregistrer</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
