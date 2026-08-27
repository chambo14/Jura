<?php

use App\Enums\ProgressStatus;
use App\Enums\TaskPriority;
use App\Exceptions\SuggestionIndisponible;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\Task;
use App\Models\User;
use App\Services\AiSuggestionService;
use App\Services\AttachmentService;
use App\Services\AvancementService;
use App\Services\ChargeService;
use App\Support\Avancement\AvancementEquipe;
use App\Support\Echeances\Echeances;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Tableau d'avancement.
 *
 * Les colonnes sont les statuts déjà utilisés partout ailleurs dans
 * l'application : une carte déplacée ici change le statut de la tâche, et
 * ce statut alimente le plan de charge, la santé du projet et le flash
 * report. Il n'y a pas deux vérités.
 *
 * L'écran sert aussi bien un projet (onglet « Tableau ») que le portefeuille
 * entier (menu « Tableau des équipes »), d'où le projet optionnel.
 */
new class extends Component {
    use WithFileUploads;

    public ?Project $project = null;

    public string $filtreProjet = '';

    public string $filtreEquipe = '';

    public string $filtreAssignee = '';

    public string $filtrePriorite = '';

    public string $recherche = '';

    public bool $seulementMoi = false;

    public bool $grouperParEquipe = false;

    public bool $afficherAnnulees = false;

    public bool $afficherAvancement = true;

    // Carte ouverte dans le panneau latéral.
    public ?int $carteId = null;

    public string $carteProjet = '';

    public string $carteLibelle = '';

    public string $carteStatut = ProgressStatus::NonDemarre->value;

    public string $cartePriorite = TaskPriority::Normale->value;

    public string $carteAssignee = '';

    public string $cartePhase = '';

    public string $carteDebut = '';

    public string $carteEcheance = '';

    public int $carteAvancement = 0;

    public string $carteDescription = '';

    public string $nouveauCommentaire = '';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $fichiers = [];

    /** @var array<int, string> */
    public array $suggestions = [];

    public function mount(?Project $project = null): void
    {
        if ($project?->exists) {
            $this->authorize('view', $project);
            $this->project = $project;
        }
    }

    /**
     * Colonnes du tableau. « Annulé » n'en fait pas partie par défaut : c'est
     * une sortie de route, pas une étape du travail — on l'affiche à la
     * demande pour retrouver une carte abandonnée.
     *
     * @return array<int, ProgressStatus>
     */
    #[Computed]
    public function colonnes(): array
    {
        $colonnes = [
            ProgressStatus::NonDemarre,
            ProgressStatus::EnCours,
            ProgressStatus::Bloque,
            ProgressStatus::Termine,
        ];

        if ($this->afficherAnnulees) {
            $colonnes[] = ProgressStatus::Annule;
        }

        return $colonnes;
    }

    /**
     * Projets ouverts à l'utilisateur, pour le filtre du tableau global.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projets(): Collection
    {
        return Project::visibleTo(auth()->user())->orderBy('nom')->get();
    }

    /**
     * @return Collection<int, Task>
     */
    #[Computed]
    public function cartes(): Collection
    {
        $projets = $this->project
            ? collect([$this->project->id])
            : $this->projets()->pluck('id');

        return Task::query()
            ->with(['assignee', 'project', 'projectPhase.phase'])
            ->withCount(['comments', 'attachments'])
            ->whereIn('project_id', $projets)
            ->when($this->filtreProjet !== '', fn ($q) => $q->where('project_id', $this->filtreProjet))
            ->when($this->filtreAssignee !== '', fn ($q) => $q->where('assignee_id', $this->filtreAssignee))
            ->when($this->filtrePriorite !== '', fn ($q) => $q->where('priorite', $this->filtrePriorite))
            ->when($this->seulementMoi, fn ($q) => $q->where('assignee_id', auth()->id()))
            ->when($this->recherche !== '', fn ($q) => $q->where('libelle', 'like', '%'.$this->recherche.'%'))
            ->when(
                $this->filtreEquipe !== '',
                fn ($q) => $q->whereHas('assignee', fn ($a) => $a->where('equipe', $this->filtreEquipe)),
            )
            ->when(! $this->afficherAnnulees, fn ($q) => $q->where('statut', '!=', ProgressStatus::Annule->value))
            ->orderBy('ordre')
            ->orderByRaw('date_echeance IS NULL')
            ->orderBy('date_echeance')
            ->orderBy('id')
            ->get();
    }

    /**
     * Le tableau se lit soit d'un bloc, soit une bande par équipe métier.
     * La clé vide est le cas courant : un seul bloc, sans intitulé.
     *
     * @return Collection<string, Collection<int, Task>>
     */
    #[Computed]
    public function bandes(): Collection
    {
        if ($this->cartes()->isEmpty()) {
            return collect();
        }

        if (! $this->grouperParEquipe) {
            return collect(['' => $this->cartes()]);
        }

        return $this->cartes()
            ->groupBy(fn (Task $carte) => $carte->assignee?->equipe ?: 'Sans équipe')
            ->sortKeys();
    }

    /**
     * Équipes métiers rencontrées sur le portefeuille visible.
     *
     * @return Collection<int, string>
     */
    #[Computed]
    public function equipes(): Collection
    {
        return User::query()
            ->whereNotNull('equipe')
            ->where('equipe', '!=', '')
            ->distinct()
            ->orderBy('equipe')
            ->pluck('equipe');
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function collaborateurs(): Collection
    {
        return User::actifs()->orderBy('name')->get();
    }

    /**
     * Compteurs de tête : ce que le comité regarde en premier.
     *
     * @return array{total: int, en_cours: int, bloquees: int, echeances: Echeances}
     */
    #[Computed]
    public function synthese(): array
    {
        $cartes = $this->cartes();

        return [
            'total' => $cartes->count(),
            'en_cours' => $cartes->where('statut', ProgressStatus::EnCours)->count(),
            'bloquees' => $cartes->where('statut', ProgressStatus::Bloque)->count(),
            'echeances' => Echeances::de($cartes),
        ];
    }

    /**
     * Avancement constaté sur les cartes affichées, équipe par équipe.
     *
     * Le calcul suit les filtres : restreindre le tableau à un projet ou à une
     * priorité restreint la mesure d'autant, et le panneau décrit toujours
     * exactement ce qui est sous les yeux.
     *
     * @return Collection<int, AvancementEquipe>
     */
    #[Computed]
    public function avancement(): Collection
    {
        return app(AvancementService::class)->parEquipe($this->cartes());
    }

    public function peutContribuerSur(Project $projet): bool
    {
        return auth()->user()->can('contribute', $projet);
    }

    /**
     * Déplacement d'une carte : nouvelle colonne, et rang donné par la carte
     * devant laquelle on l'a lâchée (aucune = en fin de colonne).
     */
    public function deplacer(int $id, string $statut, ?int $avantId = null): void
    {
        $carte = $this->cartes()->firstWhere('id', $id);

        if (! $carte) {
            return;
        }

        $this->authorize('contribute', $carte->project);

        $cible = ProgressStatus::from($statut);

        if ($cible !== $carte->statut) {
            $carte->update($this->changementDeStatut($carte, $cible));
        }

        $colonne = $this->cartes()
            ->where('statut', $cible)
            ->reject(fn (Task $autre) => $autre->id === $carte->id)
            ->values();

        $position = $avantId !== null
            ? $colonne->search(fn (Task $autre) => $autre->id === $avantId)
            : false;

        $colonne->splice($position === false ? $colonne->count() : $position, 0, [$carte]);

        foreach ($colonne as $rang => $autre) {
            if ((int) $autre->ordre !== $rang + 1) {
                Task::whereKey($autre->id)->update(['ordre' => $rang + 1]);
            }
        }

        $this->rafraichir();
    }

    /**
     * Ce qu'entraîne un changement de colonne au-delà du statut : une carte
     * lâchée dans « Terminé » est réputée faite du jour, une carte qui en
     * ressort perd sa date de réalisation.
     *
     * @return array<string, mixed>
     */
    private function changementDeStatut(Task $carte, ProgressStatus $cible): array
    {
        $attributs = ['statut' => $cible];

        if ($cible === ProgressStatus::Termine) {
            $attributs['avancement_pct'] = 100;
            $attributs['date_realisation'] = $carte->date_realisation ?? now()->toDateString();
        } elseif ($carte->statut === ProgressStatus::Termine) {
            $attributs['date_realisation'] = null;
            $attributs['avancement_pct'] = $carte->avancement_pct >= 100 ? 50 : $carte->avancement_pct;
        }

        if ($cible === ProgressStatus::EnCours && $carte->avancement_pct <= 0) {
            $attributs['avancement_pct'] = 10;
        }

        return $attributs;
    }

    /**
     * Ouvre la fenêtre de saisie. Le projet n'est pas un préalable : sur le
     * tableau du portefeuille, il se choisit dans la fenêtre — refuser
     * l'ouverture laisserait un bouton qui ne fait rien.
     */
    public function nouvelleCarte(): void
    {
        $this->reset(
            'carteId', 'carteProjet', 'carteLibelle', 'carteAssignee', 'cartePhase', 'carteDebut',
            'carteEcheance', 'carteDescription', 'nouveauCommentaire', 'fichiers', 'suggestions',
        );

        $this->carteProjet = (string) ($this->projetDeSaisie()?->id ?? '');
        $this->carteStatut = ProgressStatus::NonDemarre->value;
        $this->cartePriorite = TaskPriority::Normale->value;
        $this->carteAvancement = 0;

        Flux::modal('carte')->show();
    }

    public function ouvrirCarte(int $id): void
    {
        $carte = $this->carteOuFalse($id);

        if (! $carte) {
            return;
        }

        $this->authorize('view', $carte->project);

        $this->carteId = $carte->id;
        $this->carteLibelle = $carte->libelle;
        $this->carteStatut = $carte->statut->value;
        $this->cartePriorite = $carte->priorite->value;
        $this->carteAssignee = (string) ($carte->assignee_id ?? '');
        $this->cartePhase = (string) ($carte->project_phase_id ?? '');
        $this->carteDebut = $carte->date_debut?->format('Y-m-d') ?? '';
        $this->carteEcheance = $carte->date_echeance?->format('Y-m-d') ?? '';
        $this->carteAvancement = (int) $carte->avancement_pct;
        $this->carteDescription = $carte->description ?? '';
        $this->reset('carteProjet', 'nouveauCommentaire', 'fichiers', 'suggestions');

        Flux::modal('carte')->show();
    }

    public function enregistrerCarte(): void
    {
        $carte = $this->carteId ? $this->carteOuFalse($this->carteId) : null;
        $projet = $carte?->project ?? $this->projetDeSaisie();

        if (! $projet) {
            $this->addError('carteProjet', 'Choisissez le projet auquel rattacher cette carte.');

            return;
        }

        $this->authorize('contribute', $projet);

        $donnees = $this->validate([
            'carteLibelle' => ['required', 'string', 'max:500'],
            'carteStatut' => ['required', 'string'],
            'cartePriorite' => ['required', 'string'],
            'carteAssignee' => ['nullable', 'exists:users,id'],
            'cartePhase' => ['nullable', 'exists:project_phases,id'],
            'carteDebut' => ['nullable', 'date'],
            'carteEcheance' => ['nullable', 'date', 'after_or_equal:carteDebut'],
            'carteAvancement' => ['required', 'integer', 'min:0', 'max:100'],
            'carteDescription' => ['nullable', 'string', 'max:4000'],
        ], attributes: [
            'carteLibelle' => 'libellé',
            'carteEcheance' => 'échéance',
        ]);

        $statut = ProgressStatus::from($donnees['carteStatut']);

        $attributs = [
            'libelle' => $donnees['carteLibelle'],
            'statut' => $statut,
            'priorite' => TaskPriority::from($donnees['cartePriorite']),
            'assignee_id' => $donnees['carteAssignee'] ?: null,
            'project_phase_id' => $donnees['cartePhase'] ?: null,
            'date_debut' => $donnees['carteDebut'] ?: null,
            'date_echeance' => $donnees['carteEcheance'] ?: null,
            'avancement_pct' => $statut === ProgressStatus::Termine ? 100 : $donnees['carteAvancement'],
            'description' => $donnees['carteDescription'] ?: null,
        ];

        if ($statut === ProgressStatus::Termine) {
            $attributs['date_realisation'] = $carte?->date_realisation ?? now()->toDateString();
        }

        // Confier une carte à quelqu'un d'autre, c'est déléguer : on trace qui.
        if ($attributs['assignee_id'] && (int) $attributs['assignee_id'] !== auth()->id()) {
            $attributs['delegue_par_id'] = auth()->id();
        }

        if ($carte) {
            $carte->update($attributs);
        } else {
            $carte = $projet->tasks()->create($attributs + [
                'ordre' => (int) $projet->tasks()->max('ordre') + 1,
            ]);
            $this->carteId = $carte->id;
        }

        // Sans rattachement, le porteur ne verrait pas le projet et n'apparaîtrait
        // dans aucun plan de charge.
        $rattache = app(ChargeService::class)->rattacherParDelegation($carte);

        $this->rafraichir();

        Flux::modal('carte')->close();
        Flux::toast(
            variant: 'success',
            text: $rattache
                ? 'Carte enregistrée — '.$rattache->user->name.' a été rattaché au projet comme contributeur.'
                : 'Carte enregistrée.',
        );
    }

    public function supprimerCarte(): void
    {
        $carte = $this->carteId ? $this->carteOuFalse($this->carteId) : null;

        if (! $carte) {
            return;
        }

        $this->authorize('contribute', $carte->project);

        $carte->delete();

        $this->reset('carteId');
        $this->rafraichir();

        Flux::modal('carte')->close();
        Flux::toast(variant: 'success', text: 'Carte supprimée.');
    }

    /**
     * Commenter n'est pas contribuer : le métier qui suit une carte doit
     * pouvoir répondre sans avoir le droit de la modifier.
     */
    public function commenter(): void
    {
        $carte = $this->carteId ? $this->carteOuFalse($this->carteId) : null;

        abort_unless($carte !== null, 404);

        $this->authorize('view', $carte->project);

        $this->validate(
            ['nouveauCommentaire' => ['required', 'string', 'max:2000']],
            attributes: ['nouveauCommentaire' => 'commentaire'],
        );

        $carte->comments()->create([
            'auteur_id' => auth()->id(),
            'corps' => $this->nouveauCommentaire,
        ]);

        $this->reset('nouveauCommentaire');
        $this->rafraichir();
    }

    public function deposerFichiers(): void
    {
        $carte = $this->carteId ? $this->carteOuFalse($this->carteId) : null;

        abort_unless($carte !== null, 404);

        $this->authorize('contribute', $carte->project);

        $this->validate([
            'fichiers' => ['required', 'array', 'min:1', 'max:5'],
            'fichiers.*' => [
                'file',
                'max:'.(int) config('documents.taille_max_ko'),
                'mimes:'.implode(',', (array) config('documents.extensions')),
            ],
        ], attributes: ['fichiers' => 'fichiers', 'fichiers.*' => 'fichier']);

        foreach ($this->fichiers as $fichier) {
            app(AttachmentService::class)->deposer($carte, $fichier, auth()->user());
        }

        $this->reset('fichiers');
        $this->rafraichir();

        Flux::toast(variant: 'success', text: 'Fichier déposé sur la carte.');
    }

    public function retirerFichier(int $id): void
    {
        $carte = $this->carteId ? $this->carteOuFalse($this->carteId) : null;

        abort_unless($carte !== null, 404);

        $piece = $carte->attachments()->findOrFail($id);

        $this->authorize('delete', $piece);

        app(AttachmentService::class)->supprimer($piece);

        $this->rafraichir();
    }

    /**
     * Propositions de rédaction pour la description de la carte. Rien n'est
     * enregistré : l'utilisateur reprend la formulation qui lui convient.
     */
    public function suggererDescription(): void
    {
        $carte = $this->carteId ? $this->carteOuFalse($this->carteId) : null;

        abort_unless($carte !== null, 404);

        $this->authorize('contribute', $carte->project);

        try {
            $this->suggestions = app(AiSuggestionService::class)
                ->descriptionTache($carte, $this->carteDescription);
        } catch (SuggestionIndisponible $e) {
            $this->suggestions = [];

            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    public function appliquerSuggestion(int $rang): void
    {
        if (isset($this->suggestions[$rang])) {
            $this->carteDescription = $this->suggestions[$rang];
            $this->suggestions = [];
        }
    }

    #[Computed]
    public function assistantDisponible(): bool
    {
        return app(AiSuggestionService::class)->disponible();
    }

    /**
     * La carte ouverte, rechargée depuis la base — la collection du tableau
     * ne porte ni ses commentaires ni ses pièces jointes.
     */
    #[Computed]
    public function carte(): ?Task
    {
        return $this->carteId
            ? Task::with(['project', 'comments.auteur', 'attachments.deposePar'])->find($this->carteId)
            : null;
    }

    /**
     * Phases du projet de la carte, pour la rattacher au bon jalon MPM.
     *
     * @return Collection<int, ProjectPhase>
     */
    #[Computed]
    public function phasesProjet(): Collection
    {
        $projet = $this->carte()?->project ?? $this->projetDeSaisie();

        return $projet
            ? $projet->phases()->with('phase')->get()
            : collect();
    }

    #[Computed]
    public function peutModifierCarte(): bool
    {
        $projet = $this->carte()?->project ?? $this->projetDeSaisie();

        if ($projet) {
            return auth()->user()->can('contribute', $projet);
        }

        // Carte neuve dont le projet n'est pas encore choisi : la saisie
        // s'ouvre dès lors qu'il existe un projet où la déposer.
        return $this->carteId === null && $this->peutCreerCarte();
    }

    /**
     * Le projet dans lequel une nouvelle carte serait créée : celui de
     * l'onglet, sinon celui choisi dans la fenêtre, sinon celui du filtre
     * quand le tableau couvre le portefeuille.
     */
    private function projetDeSaisie(): ?Project
    {
        if ($this->project) {
            return $this->project;
        }

        foreach ([$this->carteProjet, $this->filtreProjet] as $choix) {
            if ($choix !== '') {
                return $this->projets()->firstWhere('id', (int) $choix);
            }
        }

        return null;
    }

    /**
     * Projets où l'utilisateur peut ouvrir une carte. Sans aucun, le bouton
     * n'a pas lieu d'être.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projetsOuContribuer(): Collection
    {
        return $this->projets()->filter(
            fn (Project $projet) => auth()->user()->can('contribute', $projet),
        )->values();
    }

    #[Computed]
    public function peutCreerCarte(): bool
    {
        return $this->project
            ? auth()->user()->can('contribute', $this->project)
            : $this->projetsOuContribuer()->isNotEmpty();
    }

    /**
     * Une carte du périmètre visible, ou rien : l'identifiant vient du
     * navigateur, il ne donne pas accès à un projet fermé.
     */
    private function carteOuFalse(int $id): ?Task
    {
        return Task::query()
            ->whereIn('project_id', Project::visibleTo(auth()->user())->select('id'))
            ->when($this->project, fn ($q) => $q->where('project_id', $this->project->id))
            ->find($id);
    }

    private function rafraichir(): void
    {
        unset(
            $this->cartes, $this->bandes, $this->synthese, $this->carte,
            $this->phasesProjet, $this->projetsOuContribuer, $this->peutCreerCarte,
        );
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        if (! $this->project) {
            $view->title('Tableau des équipes');
        }
    }
}; ?>

<div class="flex w-full flex-1 flex-col gap-6">
    @unless ($project)
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <flux:heading size="xl">Tableau des équipes</flux:heading>
                <flux:text class="mt-1">
                    Le travail en cours, colonne par colonne. Déplacer une carte change le statut de la tâche
                    dans le projet — et donc le plan de charge et le flash report.
                </flux:text>
            </div>

            @if ($this->peutCreerCarte())
                <flux:button wire:click="nouvelleCarte" variant="primary" size="sm" icon="plus">Nouvelle carte</flux:button>
            @endif
        </div>
    @endunless

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat label="Cartes" :value="$this->synthese()['total']" icon="rectangle-stack" color="blue" />
        <x-stat label="En cours" :value="$this->synthese()['en_cours']" icon="play" color="violet" />
        <x-stat label="Bloquées" :value="$this->synthese()['bloquees']" icon="exclamation-triangle" color="red" />
        @php $echeances = $this->synthese()['echeances']; @endphp
        <x-stat
            label="En retard"
            :value="$echeances->valeur()"
            :sub="$echeances->precision()"
            icon="clock"
            :color="$echeances->couleur()"
        />
    </div>

    {{-- Filtres --}}
    <div class="flex flex-wrap items-end gap-3">
        @unless ($project)
            <flux:select wire:model.live="filtreProjet" label="Projet" class="w-56">
                <flux:select.option value="">Tous les projets</flux:select.option>
                @foreach ($this->projets() as $projet)
                    <flux:select.option :value="$projet->id">{{ $projet->code }} — {{ $projet->nom }}</flux:select.option>
                @endforeach
            </flux:select>
        @endunless

        <flux:select wire:model.live="filtreEquipe" label="Équipe métier" class="w-48">
            <flux:select.option value="">Toutes</flux:select.option>
            @foreach ($this->equipes() as $equipe)
                <flux:select.option :value="$equipe">{{ $equipe }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="filtreAssignee" label="Assigné à" class="w-48">
            <flux:select.option value="">Tous</flux:select.option>
            @foreach ($this->collaborateurs() as $collaborateur)
                <flux:select.option :value="$collaborateur->id">{{ $collaborateur->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="filtrePriorite" label="Priorité" class="w-40">
            <flux:select.option value="">Toutes</flux:select.option>
            @foreach (TaskPriority::cases() as $cas)
                <flux:select.option :value="$cas->value">{{ $cas->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:input
            wire:model.live.debounce.300ms="recherche"
            label="Recherche"
            placeholder="Libellé de la carte…"
            icon="magnifying-glass"
            class="w-56"
        />

        <div class="flex flex-wrap items-center gap-4 pb-2">
            <flux:checkbox wire:model.live="seulementMoi" label="Mes cartes" />
            <flux:checkbox wire:model.live="grouperParEquipe" label="Par équipe" />
            <flux:checkbox wire:model.live="afficherAnnulees" label="Annulées" />
            <flux:checkbox wire:model.live="afficherAvancement" label="Avancement" />
        </div>

        @if ($project && $this->peutCreerCarte())
            <flux:spacer />
            <flux:button wire:click="nouvelleCarte" variant="primary" size="sm" icon="plus" class="mb-1">
                Nouvelle carte
            </flux:button>
        @endif
    </div>

    {{-- Avancement constaté, équipe par équipe --}}
    @if ($afficherAvancement && $this->avancement()->isNotEmpty())
        <div class="relative">
            <x-chargement
                cible="filtreProjet,filtreEquipe,filtreAssignee,filtrePriorite,recherche,seulementMoi,afficherAnnulees"
                texte="Recalcul de l'avancement…"
                class="rounded-xl"
            />

            <div wire:loading.class="opacity-40" class="grid gap-3 transition-opacity sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->avancement() as $ligne)
                    @php
                        $progression = $ligne->progression();
                        $ecart = round($ligne->tauxDeclare - $ligne->taux, 1);
                    @endphp

                    <div
                        wire:key="avancement-{{ $loop->index }}"
                        class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="truncate font-medium text-zinc-900 dark:text-zinc-100">{{ $ligne->equipe }}</div>
                                <div class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $ligne->terminees }}/{{ $ligne->cartes }} {{ Str::plural('carte', $ligne->cartes) }}
                                    · {{ rtrim(rtrim(number_format($ligne->chargeTotale, 1, ',', ' '), '0'), ',') }} j de charge
                                    @if ($ligne->enRetard > 0)
                                        · <span class="text-amber-600 dark:text-amber-400">{{ $ligne->enRetard }} en retard</span>
                                    @endif
                                </div>
                            </div>

                            <div class="shrink-0 text-right">
                                <div class="text-2xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-100">
                                    {{ number_format($ligne->taux, 0) }} %
                                </div>
                                @if ($progression != 0.0)
                                    <div @class([
                                        'text-xs font-medium tabular-nums',
                                        'text-emerald-600 dark:text-emerald-400' => $progression > 0,
                                        'text-red-600 dark:text-red-400' => $progression < 0,
                                    ])>
                                        {{ $progression > 0 ? '+' : '' }}{{ number_format($progression, 1, ',', ' ') }} pts
                                    </div>
                                @endif
                            </div>
                        </div>

                        {{-- Barre pleine : la charge close. Barre pâle : ce que les
                             cartes ouvertes déclarent en plus. --}}
                        <div class="relative h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                            <div class="absolute inset-y-0 left-0 rounded-full bg-blue-200 dark:bg-blue-500/40" style="width: {{ $ligne->tauxDeclare }}%"></div>
                            <div class="absolute inset-y-0 left-0 rounded-full bg-blue-600 dark:bg-blue-400" style="width: {{ $ligne->taux }}%"></div>
                        </div>

                        @php $courbe = $ligne->courbe(); @endphp
                        @if ($courbe !== '')
                            <svg
                                viewBox="0 0 100 100"
                                preserveAspectRatio="none"
                                class="h-10 w-full text-blue-500 dark:text-blue-400"
                                aria-hidden="true"
                            >
                                <polyline
                                    points="{{ $courbe }}"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="3"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    vector-effect="non-scaling-stroke"
                                />
                            </svg>
                        @endif

                        <div class="flex items-center justify-between gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                            {{-- La courbe commence à la première semaine où l'équipe
                                 avait des cartes, qui n'est pas toujours la plus ancienne. --}}
                            <span>
                                @if ($debut = $ligne->evolution->first())
                                    depuis le {{ $debut->date->translatedFormat('j F') }}
                                @endif
                            </span>
                            @if ($ecart > 0)
                                <span title="Travail entamé sur des cartes encore ouvertes">
                                    déclaré {{ number_format($ligne->tauxDeclare, 0) }} % (+{{ number_format($ecart, 0) }})
                                </span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Bandes : un seul bloc, ou une bande par équipe métier --}}
    <div class="relative">
        <x-chargement
            cible="filtreProjet,filtreEquipe,filtreAssignee,filtrePriorite,recherche,seulementMoi,grouperParEquipe,afficherAnnulees"
            texte="Recomposition du tableau…"
            class="rounded-xl"
        />

        <div wire:loading.class="opacity-40" class="flex flex-col gap-6 transition-opacity">
    @forelse ($this->bandes() as $equipe => $cartesBande)
        <div wire:key="bande-{{ $equipe ?: 'tout' }}" class="flex flex-col gap-3">
            @if ($equipe !== '')
                <div class="flex items-center gap-2">
                    <flux:heading size="lg">{{ $equipe }}</flux:heading>
                    <flux:badge size="sm" color="zinc">{{ $cartesBande->count() }}</flux:badge>
                </div>
            @endif

            <div class="flex gap-4 overflow-x-auto pb-2">
                @foreach ($this->colonnes() as $colonne)
                    @php $cartesColonne = $cartesBande->where('statut', $colonne)->values(); @endphp

                    <div
                        wire:key="colonne-{{ $equipe ?: 'tout' }}-{{ $colonne->value }}"
                        x-data="{ survol: false }"
                        x-bind:class="survol ? 'border-blue-400 dark:border-blue-500' : 'border-zinc-200 dark:border-zinc-700'"
                        class="flex w-72 shrink-0 flex-col rounded-xl border bg-zinc-50 transition dark:bg-zinc-900/60"
                        x-on:dragover.prevent="survol = true"
                        x-on:dragleave="survol = false"
                        x-on:drop.prevent="survol = false; $wire.deplacer(Number($event.dataTransfer.getData('text/plain')), '{{ $colonne->value }}', null)"
                    >
                        <div class="flex items-center justify-between gap-2 border-b border-zinc-200 px-3 py-2 dark:border-zinc-700">
                            <div class="flex items-center gap-2">
                                <span @class([
                                    'size-2 rounded-full',
                                    'bg-zinc-400' => $colonne->color() === 'zinc',
                                    'bg-blue-500' => $colonne->color() === 'blue',
                                    'bg-green-500' => $colonne->color() === 'green',
                                    'bg-red-500' => $colonne->color() === 'red',
                                ])></span>
                                <span class="text-sm font-medium">{{ $colonne->label() }}</span>
                            </div>
                            <flux:badge size="sm" color="zinc">{{ $cartesColonne->count() }}</flux:badge>
                        </div>

                        <div class="flex min-h-24 flex-col gap-2 p-2">
                            @foreach ($cartesColonne as $carte)
                                @php $modifiable = $this->peutContribuerSur($carte->project); @endphp

                                <div
                                    wire:key="carte-{{ $carte->id }}"
                                    draggable="{{ $modifiable ? 'true' : 'false' }}"
                                    x-on:dragstart="$event.dataTransfer.setData('text/plain', '{{ $carte->id }}'); $event.dataTransfer.effectAllowed = 'move'"
                                    x-on:dragover.prevent.stop
                                    x-on:drop.prevent.stop="$wire.deplacer(Number($event.dataTransfer.getData('text/plain')), '{{ $colonne->value }}', {{ $carte->id }})"
                                    wire:click="ouvrirCarte({{ $carte->id }})"
                                    @class([
                                        'cursor-pointer rounded-lg border bg-white p-3 shadow-xs transition hover:border-zinc-300 dark:bg-zinc-900 dark:hover:border-zinc-600',
                                        'border-red-300 dark:border-red-800' => $carte->enRetard(),
                                        'border-zinc-200 dark:border-zinc-700' => ! $carte->enRetard(),
                                    ])
                                >
                                    @unless ($project)
                                        <div class="mb-1 truncate text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ $carte->project->code }}
                                        </div>
                                    @endunless

                                    <div class="text-sm font-medium">{{ $carte->libelle }}</div>

                                    @if ($carte->avancement_pct > 0 && $carte->avancement_pct < 100)
                                        <x-progress-bar class="mt-2" :value="$carte->avancement_pct" color="blue" />
                                    @endif

                                    <div class="mt-2 flex flex-wrap items-center gap-2">
                                        <flux:badge :color="$carte->priorite->color()" size="sm">
                                            {{ $carte->priorite->label() }}
                                        </flux:badge>

                                        @if ($carte->date_echeance)
                                            <span @class([
                                                'text-xs tabular-nums',
                                                'font-medium text-red-600 dark:text-red-400' => $carte->enRetard(),
                                                'text-zinc-500 dark:text-zinc-400' => ! $carte->enRetard(),
                                            ])>{{ $carte->date_echeance->format('d/m') }}</span>
                                        @endif

                                        @if ($carte->comments_count)
                                            <span class="flex items-center gap-1 text-xs text-zinc-500 dark:text-zinc-400">
                                                <flux:icon name="chat-bubble-left" variant="micro" />{{ $carte->comments_count }}
                                            </span>
                                        @endif

                                        @if ($carte->attachments_count)
                                            <span class="flex items-center gap-1 text-xs text-zinc-500 dark:text-zinc-400">
                                                <flux:icon name="paper-clip" variant="micro" />{{ $carte->attachments_count }}
                                            </span>
                                        @endif

                                        <flux:spacer />

                                        @if ($carte->assignee)
                                            <flux:tooltip :content="$carte->assignee->name">
                                                <flux:avatar :name="$carte->assignee->name" size="xs" />
                                            </flux:tooltip>
                                        @endif
                                    </div>
                                </div>
                            @endforeach

                            @if ($cartesColonne->isEmpty())
                                <div class="rounded-lg border border-dashed border-zinc-200 px-3 py-6 text-center text-xs text-zinc-400 dark:border-zinc-700">
                                    Déposer une carte ici
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div class="rounded-xl border border-dashed border-zinc-200 px-4 py-16 text-center dark:border-zinc-700">
            <flux:text>Aucune carte ne correspond à ces critères.</flux:text>
        </div>
    @endforelse
        </div>
    </div>

    {{-- Panneau de la carte : le détail, la discussion et les pièces jointes --}}
    <flux:modal name="carte" class="md:w-[44rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $carteId ? 'Carte' : 'Nouvelle carte' }}</flux:heading>
                @if ($this->carte())
                    <flux:text size="sm" class="mt-1">
                        {{ $this->carte()->project->code }} — {{ $this->carte()->project->nom }}
                    </flux:text>
                @endif
            </div>

            <form wire:submit="enregistrerCarte" class="space-y-4">
                @if (! $carteId && ! $project)
                    <flux:select
                        wire:model.live="carteProjet"
                        label="Projet"
                        placeholder="Choisir un projet…"
                        required
                    >
                        {{-- L'invite vient de `placeholder` : une option vide de plus
                             ferait doublon, et Flux la rendrait de toute façon inerte. --}}
                        @foreach ($this->projetsOuContribuer() as $projet)
                            <flux:select.option :value="$projet->id">{{ $projet->code }} — {{ $projet->nom }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                <flux:input wire:model="carteLibelle" label="Libellé" required :disabled="! $this->peutModifierCarte()" />

                <div class="grid gap-3 sm:grid-cols-3">
                    <flux:select wire:model="carteStatut" label="Statut" :disabled="! $this->peutModifierCarte()">
                        @foreach (ProgressStatus::cases() as $cas)
                            <flux:select.option :value="$cas->value">{{ $cas->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model="cartePriorite" label="Priorité" :disabled="! $this->peutModifierCarte()">
                        @foreach (TaskPriority::cases() as $cas)
                            <flux:select.option :value="$cas->value">{{ $cas->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:input
                        wire:model="carteAvancement"
                        type="number"
                        min="0"
                        max="100"
                        label="Avancement (%)"
                        :disabled="! $this->peutModifierCarte()"
                    />
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    {{-- Le rattachement automatique d'un collaborateur hors projet est
                         annoncé après enregistrement, au moment où il se produit : une
                         description permanente ici déséquilibrait la ligne pour rien. --}}
                    <flux:select
                        wire:model="carteAssignee"
                        label="Assigné à"
                        :disabled="! $this->peutModifierCarte()"
                    >
                        <flux:select.option value="">Non assigné</flux:select.option>
                        @foreach ($this->collaborateurs() as $collaborateur)
                            <flux:select.option :value="$collaborateur->id">
                                {{ $collaborateur->name }}@if ($collaborateur->equipe) — {{ $collaborateur->equipe }} @endif
                            </flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model="cartePhase" label="Phase MPM" :disabled="! $this->peutModifierCarte()">
                        <flux:select.option value="">Aucune</flux:select.option>
                        @foreach ($this->phasesProjet() as $phase)
                            <flux:select.option :value="$phase->id">{{ $phase->phase->nom }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <flux:input wire:model="carteDebut" type="date" label="Début" :disabled="! $this->peutModifierCarte()" />
                    <flux:input wire:model="carteEcheance" type="date" label="Échéance" :disabled="! $this->peutModifierCarte()" />
                </div>

                <div>
                    <flux:textarea
                        wire:model="carteDescription"
                        label="Description"
                        rows="3"
                        :disabled="! $this->peutModifierCarte()"
                    />

                    @if ($carteId && $this->peutModifierCarte() && $this->assistantDisponible())
                        <div class="mt-2 flex items-center gap-2">
                            <flux:button
                                type="button"
                                wire:click="suggererDescription"
                                wire:loading.attr="disabled"
                                wire:target="suggererDescription"
                                icon="sparkles"
                                variant="ghost"
                                size="xs"
                            >
                                Proposer une rédaction
                            </flux:button>

                            <flux:text size="sm" wire:loading wire:target="suggererDescription">Rédaction en cours…</flux:text>
                        </div>

                        @if ($suggestions !== [])
                            <x-suggestion-list :suggestions="$suggestions" class="mt-2" />
                        @endif
                    @endif
                </div>

                @if ($this->peutModifierCarte())
                    <div class="flex items-center justify-end gap-2">
                        @if ($carteId)
                            <flux:button
                                type="button"
                                wire:click="supprimerCarte"
                                wire:confirm="Supprimer cette carte ?"
                                variant="ghost"
                                size="sm"
                                icon="trash"
                            >
                                Supprimer
                            </flux:button>
                        @endif

                        <flux:spacer />

                        <flux:modal.close>
                            <flux:button variant="ghost">Fermer</flux:button>
                        </flux:modal.close>

                        <flux:button type="submit" variant="primary">Enregistrer</flux:button>
                    </div>
                @endif
            </form>

            @if ($this->carte())
                <flux:separator />

                {{-- Pièces jointes de la carte --}}
                <div class="space-y-2">
                    <flux:heading size="sm">Pièces jointes</flux:heading>

                    <x-attachment-list
                        :attachments="$this->carte()->attachments"
                        :peut-retirer="$this->peutModifierCarte()"
                        vide="Aucun fichier sur cette carte."
                    />

                    @if ($this->peutModifierCarte())
                        <form wire:submit="deposerFichiers" class="flex items-end gap-2">
                            <flux:input type="file" wire:model="fichiers" multiple class="flex-1" />
                            <flux:button type="submit" size="sm" icon="arrow-up-tray">Déposer</flux:button>
                        </form>
                    @endif
                </div>

                <flux:separator />

                {{-- Fil de discussion --}}
                <div class="space-y-3">
                    <flux:heading size="sm">Discussion</flux:heading>

                    @forelse ($this->carte()->comments as $commentaire)
                        <div wire:key="commentaire-{{ $commentaire->id }}" class="flex gap-3">
                            <flux:avatar :name="$commentaire->auteur?->name ?? '?'" size="xs" class="mt-0.5 shrink-0" />

                            <div class="min-w-0 flex-1">
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $commentaire->auteur?->name ?? 'Compte supprimé' }}
                                    · {{ $commentaire->created_at?->format('d/m/Y H:i') }}
                                </div>
                                <div class="whitespace-pre-line text-sm">{{ $commentaire->corps }}</div>
                            </div>
                        </div>
                    @empty
                        <flux:text size="sm">Aucun échange sur cette carte.</flux:text>
                    @endforelse

                    <form wire:submit="commenter" class="flex items-end gap-2">
                        <flux:textarea
                            wire:model="nouveauCommentaire"
                            rows="2"
                            placeholder="Écrire un commentaire…"
                            class="flex-1"
                        />
                        <flux:button type="submit" size="sm" icon="paper-airplane">Publier</flux:button>
                    </form>
                </div>
            @endif
        </div>
    </flux:modal>
</div>
