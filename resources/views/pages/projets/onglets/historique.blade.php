<?php

use App\Models\AuditEntry;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Journal des changements du projet.
 *
 * L'état courant écrasait le précédent : un chiffre porté au comité ne pouvait
 * ni être expliqué, ni être reconstitué. Cet écran répond à la seule question
 * qui compte devant un comité — « depuis quand, et sur décision de qui ? ».
 *
 * Il est en lecture seule, et le restera : un journal qu'on peut corriger ne
 * vaut rien.
 */
new class extends Component {
    use WithPagination;

    private const PAR_PAGE = 25;

    public Project $project;

    public string $filtreAttribut = '';

    public string $filtreAuteur = '';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);

        $this->project = $project;
    }

    public function updated(string $propriete): void
    {
        if (in_array($propriete, ['filtreAttribut', 'filtreAuteur'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return LengthAwarePaginator<int, AuditEntry>
     */
    #[Computed]
    public function changements(): LengthAwarePaginator
    {
        return AuditEntry::query()
            ->where('project_id', $this->project->id)
            ->when($this->filtreAttribut !== '', fn ($q) => $q->where('attribut', $this->filtreAttribut))
            ->when($this->filtreAuteur !== '', fn ($q) => $q->where('auteur_id', $this->filtreAuteur))
            ->with('auteur')
            ->recent()
            ->paginate(self::PAR_PAGE);
    }

    /**
     * Attributs réellement rencontrés dans le journal de ce projet : proposer
     * la liste complète des attributs suivis n'aiderait personne.
     *
     * @return Collection<int, string>
     */
    #[Computed]
    public function attributsRencontres(): Collection
    {
        return AuditEntry::query()
            ->where('project_id', $this->project->id)
            ->distinct()
            ->orderBy('attribut')
            ->pluck('attribut');
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function auteurs(): Collection
    {
        return User::query()
            ->whereIn('id', AuditEntry::query()
                ->where('project_id', $this->project->id)
                ->whereNotNull('auteur_id')
                ->select('auteur_id'))
            ->orderBy('name')
            ->get();
    }

    /**
     * Intitulé lisible d'un attribut technique.
     */
    public function intitule(string $attribut): string
    {
        return match ($attribut) {
            'statut' => 'Statut',
            'sante' => 'Santé',
            'categorie' => 'Rubrique du comité',
            'ordre_comite' => 'Ordre de passage',
            'priorite' => 'Priorité',
            'avancement_pct' => '% Avancement',
            'taux_realisation_pct' => 'Taux de réalisation',
            'charge_jours' => 'Charge (jours)',
            'obligatoire' => 'Caractère obligatoire',
            'date_debut' => 'Date de début',
            'date_fin_initiale' => 'Date de fin initiale',
            'date_fin_revisee' => 'Date de fin révisée',
            'date_echeance' => 'Échéance',
            'date_prevue' => 'Date prévue',
            'date_reelle' => 'Date réelle',
            'date_realisation' => 'Date de réalisation',
            'date_livraison' => 'Date de livraison',
            'chef_projet_id' => 'Chef de projet',
            'back_up_id' => 'Back-up',
            'sponsor_id' => 'Sponsor',
            'referent_technique_id' => 'Référent technique',
            'responsable_id' => 'Responsable',
            'assignee_id' => 'Assigné à',
            'phase_id' => 'Phase MPM',
            'workflow_step_id' => 'Étape courante',
            'profile_id' => 'Profil',
            default => ucfirst(str_replace('_', ' ', $attribut)),
        };
    }

    /**
     * Une référence vers un utilisateur se lit par son nom, pas par son
     * identifiant : un journal illisible n'est pas consulté.
     */
    public function valeur(AuditEntry $changement, ?string $brute): string
    {
        if ($brute === null) {
            return '—';
        }

        if (str_ends_with($changement->attribut, '_id') && ctype_digit($brute)) {
            return User::find((int) $brute)?->name ?? '#'.$brute;
        }

        return $brute;
    }
}; ?>

<div class="flex flex-col gap-5">
    <div>
        <flux:heading size="lg">Historique des changements</flux:heading>
        <flux:text class="mt-1">
            Chaque variation d'une valeur suivie — avancement, dates, statut, responsables — est
            consignée avec son auteur et son occasion. Le journal est en lecture seule.
        </flux:text>
    </div>

    @if ($this->changements()->total() === 0)
        <flux:callout icon="clock" color="zinc">
            Aucun changement consigné pour ce projet. Le journal démarre à la mise en service de
            cette fonction : les modifications antérieures ne peuvent pas être reconstituées.
        </flux:callout>
    @else
        <div class="flex flex-wrap items-end gap-3">
            <flux:select wire:model.live="filtreAttribut" label="Valeur suivie" class="w-56">
                <flux:select.option value="">Toutes</flux:select.option>
                @foreach ($this->attributsRencontres() as $attribut)
                    <flux:select.option :value="$attribut">{{ $this->intitule($attribut) }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="filtreAuteur" label="Auteur" class="w-56">
                <flux:select.option value="">Tous</flux:select.option>
                @foreach ($this->auteurs() as $auteur)
                    <flux:select.option :value="$auteur->id">{{ $auteur->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <div class="relative">
            <x-chargement cible="filtreAttribut,filtreAuteur,gotoPage,previousPage,nextPage" class="rounded-xl" />

            <div wire:loading.class="opacity-40" class="overflow-x-auto rounded-xl border border-zinc-200 transition-opacity dark:border-zinc-700">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 text-start text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-900 dark:text-zinc-400">
                        <tr>
                            <th class="px-3 py-2 text-start font-medium">Quand</th>
                            <th class="px-3 py-2 text-start font-medium">Objet</th>
                            <th class="px-3 py-2 text-start font-medium">Valeur suivie</th>
                            <th class="px-3 py-2 text-start font-medium">Avant</th>
                            <th class="px-3 py-2 text-start font-medium">Après</th>
                            <th class="px-3 py-2 text-start font-medium">Auteur</th>
                            <th class="px-3 py-2 text-start font-medium">Occasion</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($this->changements() as $changement)
                            <tr wire:key="changement-{{ $changement->id }}" class="bg-white dark:bg-zinc-900">
                                <td class="whitespace-nowrap px-3 py-2 tabular-nums text-zinc-500 dark:text-zinc-400">
                                    {{ $changement->created_at?->format('d/m/Y H:i') ?? '—' }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-zinc-500 dark:text-zinc-400">
                                    {{ $changement->objet() }}
                                </td>
                                <td class="px-3 py-2 font-medium text-zinc-900 dark:text-zinc-100">
                                    {{ $this->intitule($changement->attribut) }}
                                </td>
                                <td class="px-3 py-2 text-zinc-500 line-through dark:text-zinc-400">
                                    {{ $this->valeur($changement, $changement->ancienne_valeur) }}
                                </td>
                                <td class="px-3 py-2 font-medium text-zinc-900 dark:text-zinc-100">
                                    {{ $this->valeur($changement, $changement->nouvelle_valeur) }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-zinc-600 dark:text-zinc-300">
                                    {{ $changement->auteur?->name ?? 'Automatique' }}
                                </td>
                                <td class="px-3 py-2 text-zinc-500 dark:text-zinc-400">
                                    {{ $changement->motif ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <flux:pagination :paginator="$this->changements()" />
    @endif
</div>
