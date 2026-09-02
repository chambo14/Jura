<?php

use App\Enums\DeliverableStatus;
use App\Enums\MemberRole;
use App\Enums\ProgressStatus;
use App\Enums\ProjectCategory;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Models\Client;
use App\Models\Phase;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\ProjectStep;
use App\Models\User;
use App\Models\WorkflowStep;
use App\Services\AvancementService;
use App\Services\ProjectCodeGenerator;
use App\Services\ProjectProvisioner;
use App\Services\ProjectSuppressionService;
use App\Support\Audit\Audit;
use App\Support\Referentiel\Perimetre;
use Flux\Flux;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public ?Project $project = null;

    public string $code = '';

    /** Le code a-t-il été saisi à la main plutôt qu'attribué ? */
    public bool $codePersonnalise = false;

    /** Confirmation de suppression : le code du projet, saisi à la main. */
    public string $codeDeSuppression = '';

    public string $nom = '';

    public string $description = '';

    public string $clientId = '';

    public string $nouveauClient = '';

    public string $nouveauClientSigle = '';

    public string $typeProjet = '';

    public string $categorie = '';

    public int $ordreComite = 0;

    public string $paysDomiciliation = '';

    public string $statut = '';

    public string $dateDebut = '';

    public string $dateFinInitiale = '';

    public string $dateFinRevisee = '';

    public float $avancement = 0;

    public float $tauxRealisation = 0;

    public string $lienPlanning = '';

    public string $chefProjetId = '';

    public string $backUpId = '';

    public string $sponsorId = '';

    public string $referentTechniqueId = '';

    /**
     * Étapes du bandeau d'avancement retenues pour ce projet.
     *
     * @var array<int, int>
     */
    public array $etapes = [];

    /**
     * Phases MPM retenues pour ce projet.
     *
     * @var array<int, int>
     */
    public array $phases = [];

    public function mount(?Project $project = null): void
    {
        if (! $project?->exists) {
            // Le code est attribué d'emblée, pour que la personne qui remplit
            // le formulaire sache sous quel numéro le projet existera.
            $this->code = app(ProjectCodeGenerator::class)->suivant();
        }

        if ($project?->exists) {
            $this->authorize('update', $project);

            $this->project = $project;
            $this->code = $project->code;
            $this->codePersonnalise = true;
            $this->nom = $project->nom;
            $this->description = $project->description ?? '';
            $this->clientId = (string) $project->client_id;
            $this->typeProjet = $project->type_projet->value;
            $this->categorie = $project->categorie->value;
            $this->ordreComite = $project->ordre_comite;
            $this->paysDomiciliation = $project->pays_domiciliation ?? '';
            $this->statut = $project->statut->value;
            $this->dateDebut = $project->date_debut?->format('Y-m-d') ?? '';
            $this->dateFinInitiale = $project->date_fin_initiale?->format('Y-m-d') ?? '';
            $this->dateFinRevisee = $project->date_fin_revisee?->format('Y-m-d') ?? '';
            $this->avancement = (float) $project->avancement_pct;
            $this->tauxRealisation = (float) $project->taux_realisation_pct;
            $this->lienPlanning = $project->lien_planning ?? '';
            $this->chefProjetId = (string) ($project->chef_projet_id ?? '');
            $this->backUpId = (string) ($project->back_up_id ?? '');
            $this->sponsorId = (string) ($project->sponsor_id ?? '');
            $this->referentTechniqueId = (string) ($project->referent_technique_id ?? '');
            $this->etapes = $project->steps()->pluck('workflow_step_id')->all();
            $this->phases = $project->phases()->pluck('phase_id')->all();

            // Un projet repris d'un import ou d'un jeu de données n'a parfois
            // rien d'instancié : la fiche propose alors le référentiel complet
            // plutôt que de refuser l'enregistrement.
            if ($this->etapes === []) {
                $this->cocherToutesLesEtapes();
            }

            if ($this->phases === []) {
                $this->cocherToutesLesPhases();
            }

            return;
        }

        $this->authorize('create', Project::class);

        $this->typeProjet = ProjectType::Deploiement->value;
        $this->categorie = ProjectCategory::Autres->value;
        $this->statut = ProjectStatus::EnPreparation->value;
        // Se désigner soi-même n'a de sens que si l'on a la fonction : la
        // Direction crée des projets qu'elle ne pilote pas.
        if (User::query()->whereKey(auth()->id())->pourRoleProjet(MemberRole::ChefProjet)->exists()) {
            $this->chefProjetId = (string) auth()->id();
        }
        $this->cocherToutesLesEtapes();
        $this->cocherToutesLesPhases();
    }

    /**
     * Reprend l'avancement des cartes du projet plutôt que de le saisir.
     *
     * Le chiffre reste proposé, jamais imposé : il tombe dans le champ, et
     * c'est l'enregistrement du formulaire qui le retient. Le chef de projet
     * garde donc le dernier mot — un taux constaté sur les cartes ne connaît
     * ni les travaux non cartographiés, ni ce qui est fini mais pas encore
     * basculé.
     */
    public function reprendreDesCartes(): void
    {
        if (! $this->project) {
            return;
        }

        $cartes = $this->project->tasks()->get();

        if ($cartes->isEmpty()) {
            Flux::toast('Ce projet n\'a aucune carte : rien à reprendre.', variant: 'warning');

            return;
        }

        $service = app(AvancementService::class);

        $this->avancement = $service->tauxDeclare($cartes);
        $this->tauxRealisation = $service->taux($cartes);

        Flux::toast('Avancement repris des '.$cartes->count().' cartes du projet. Enregistrez pour le retenir.');
    }

    #[Computed]
    public function clients(): Collection
    {
        return Client::orderBy('nom')->get();
    }

    #[Computed]
    public function utilisateurs(): Collection
    {
        return User::actifs()->orderBy('name')->get();
    }

    /**
     * Personnes proposables pour un rôle du bloc « Pilotage ».
     *
     * Le compte déjà désigné sur le projet reste proposé même si son profil a
     * changé depuis : la fiche ne doit pas effacer une désignation qu'elle
     * n'aurait fait qu'ouvrir.
     *
     * @return Collection<int, User>
     */
    public function candidats(MemberRole $role): Collection
    {
        return User::actifs()
            ->pourRoleProjet($role, [(int) $this->valeurDuRole($role)])
            ->orderBy('name')
            ->get();
    }

    /**
     * Rôles du bloc « Pilotage » et propriété du formulaire qui les porte.
     *
     * @return array<string, MemberRole>
     */
    public function rolesDePilotage(): array
    {
        return [
            'chefProjetId' => MemberRole::ChefProjet,
            'backUpId' => MemberRole::BackUp,
            'sponsorId' => MemberRole::Sponsor,
            'referentTechniqueId' => MemberRole::ReferentTechnique,
        ];
    }

    /**
     * Refuse une désignation que le profil du compte ne prévoit pas.
     *
     * Une personne déjà désignée sur le projet reste acceptée même si son
     * profil a changé depuis : la fiche ne casse pas sur un existant qu'elle
     * n'a pas créé. C'est en la remplaçant qu'on applique la règle.
     */
    private function regleDuRole(MemberRole $role): Closure
    {
        return function (string $attribut, mixed $valeur, Closure $echouer) use ($role) {
            if (blank($valeur)) {
                return;
            }

            $deja = (int) ($this->project?->{$this->colonneDuRole($role)} ?? 0);

            if ((int) $valeur === $deja) {
                return;
            }

            $eligible = User::query()
                ->whereKey($valeur)
                ->pourRoleProjet($role)
                ->exists();

            if (! $eligible) {
                $echouer("La fonction de ce compte ne permet pas de tenir le rôle « {$role->label()} ». À corriger depuis l'écran « Utilisateurs ».");
            }
        };
    }

    private function colonneDuRole(MemberRole $role): string
    {
        return match ($role) {
            MemberRole::ChefProjet => 'chef_projet_id',
            MemberRole::BackUp => 'back_up_id',
            MemberRole::Sponsor => 'sponsor_id',
            default => 'referent_technique_id',
        };
    }

    private function valeurDuRole(MemberRole $role): string
    {
        $propriete = array_search($role, $this->rolesDePilotage(), true);

        return $propriete === false ? '' : (string) $this->{$propriete};
    }

    /**
     * Étapes proposées par le référentiel pour le type choisi.
     *
     * @return Collection<int, WorkflowStep>
     */
    #[Computed]
    public function etapesDisponibles(): Collection
    {
        $type = ProjectType::tryFrom($this->typeProjet);

        return $type === null ? collect() : WorkflowStep::forType($type)->get();
    }

    /**
     * Changer de type change le catalogue : les étapes de l'ancien n'ont plus
     * cours, et repartir de la sélection complète évite un bandeau à trous que
     * personne n'a choisi.
     */
    public function updatedTypeProjet(): void
    {
        unset($this->etapesDisponibles);

        $this->cocherToutesLesEtapes();
        $this->cocherToutesLesPhases();
    }

    public function cocherToutesLesEtapes(): void
    {
        $this->etapes = $this->etapesDisponibles()->pluck('id')->all();
    }

    public function decocherToutesLesEtapes(): void
    {
        $this->etapes = [];
    }

    /**
     * Les 8 phases MPM du référentiel.
     *
     * @return Collection<int, Phase>
     */
    #[Computed]
    public function phasesDisponibles(): Collection
    {
        return Phase::ordered()->get();
    }

    public function cocherToutesLesPhases(): void
    {
        $this->phases = $this->phasesDisponibles()->pluck('id')->all();
    }

    public function decocherToutesLesPhases(): void
    {
        $this->phases = [];
    }

    /**
     * Phases décochées que le projet gardera : une phase entamée, ou dont un
     * livrable a déjà été produit, ne se retire pas d'un coup de case.
     *
     * @return Collection<int, ProjectPhase>
     */
    #[Computed]
    public function phasesIrretirables(): Collection
    {
        if (! $this->project) {
            return collect();
        }

        $ecartees = $this->project->phases()
            ->whereNotIn('phase_id', $this->phases ?: [0])
            ->with('phase')
            ->get();

        $travaillees = $this->project->deliverables()
            ->whereIn('phase_id', $ecartees->pluck('phase_id'))
            ->where(fn ($q) => $q->where('statut', '!=', DeliverableStatus::AProduire->value)
                ->orWhereNotNull('date_livraison')
                ->orWhereNotNull('fichier_path')
                ->orWhereNotNull('lien'))
            ->pluck('phase_id')
            ->unique();

        return $ecartees->filter(
            fn ($phase) => $phase->statut !== ProgressStatus::NonDemarre
                || $phase->avancement_pct > 0
                || $phase->date_debut_reelle !== null
                || $phase->date_fin_reelle !== null
                || filled($phase->commentaire)
                || $travaillees->contains($phase->phase_id),
        )->values();
    }

    /**
     * Une étape décochée sur laquelle du travail est consigné ne peut pas être
     * retirée : l'écran l'annonce avant l'enregistrement plutôt qu'après.
     *
     * @return Collection<int, ProjectStep>
     */
    #[Computed]
    public function etapesIrretirables(): Collection
    {
        if (! $this->project) {
            return collect();
        }

        return $this->project->steps()
            ->whereNotIn('workflow_step_id', $this->etapes ?: [0])
            ->where(fn ($q) => $q->where('statut', '!=', ProgressStatus::NonDemarre->value)
                ->orWhereNotNull('date_reelle')
                ->orWhereNotNull('annotation'))
            ->with('workflowStep')
            ->get();
    }

    /**
     * Reprend la main sur le code attribué.
     *
     * L'attribution automatique couvre le cas courant, mais un projet repris
     * d'un suivi antérieur ou dont le code figure à un contrat doit pouvoir
     * garder le sien.
     */
    public function personnaliserLeCode(): void
    {
        $this->codePersonnalise = true;
    }

    /**
     * Rend la main : le projet reprend le prochain code de la suite.
     */
    public function reprendreLeCodeAutomatique(): void
    {
        $this->codePersonnalise = false;
        $this->code = app(ProjectCodeGenerator::class)->suivant();
        $this->resetValidation('code');
    }

    /**
     * Crée le projet, en redemandant un numéro si la place vient d'être prise.
     *
     * Deux personnes qui ouvrent le formulaire en même temps y lisent le même
     * code : c'est l'index unique de la table qui tranche à l'enregistrement.
     * Plutôt que de renvoyer une erreur à celle qui arrive seconde, on lui
     * attribue le numéro suivant — elle n'y est pour rien.
     *
     * Un code saisi à la main, lui, n'est pas remplacé : c'est un choix, et il
     * revient à son auteur d'en changer.
     *
     * @param  array<string, mixed>  $attributs
     */
    private function creerAvecUnCodeLibre(array $attributs): Project
    {
        for ($tentative = 1; $tentative <= 3; $tentative++) {
            try {
                return Project::create($attributs);
            } catch (UniqueConstraintViolationException $collision) {
                if ($this->codePersonnalise || $tentative === 3) {
                    throw $collision;
                }

                $attributs['code'] = app(ProjectCodeGenerator::class)->suivant();
                $this->code = $attributs['code'];
            }
        }

        throw new RuntimeException('Aucun code projet disponible.');
    }

    /**
     * Supprime le projet et tout ce qui en dépend.
     *
     * Le geste est sans retour : phases, étapes, tâches, livrables, jalons,
     * flash reports, pièces jointes et journal disparaissent avec lui. Il
     * n'existe que pour un projet créé par erreur — un projet arrivé à son
     * terme se clôture, un projet arrêté s'abandonne, et l'un comme l'autre
     * gardent leur histoire.
     *
     * D'où la confirmation par le code plutôt qu'un simple « êtes-vous
     * sûr ? » : on ne supprime pas un projet en cliquant à côté.
     */
    public function supprimer(): void
    {
        if (! $this->project) {
            return;
        }

        $this->authorize('delete', $this->project);

        if (trim($this->codeDeSuppression) !== $this->project->code) {
            $this->addError('codeDeSuppression', 'Saisissez le code du projet pour confirmer la suppression.');

            return;
        }

        $nom = $this->project->nom;

        Audit::pour(
            'Suppression du projet',
            fn () => app(ProjectSuppressionService::class)->supprimer($this->project),
        );

        Flux::toast(variant: 'success', text: "Projet « {$nom} » supprimé.");

        $this->redirectRoute('projets.index', navigate: true);
    }

    public function enregistrer(): void
    {
        $donnees = $this->validate([
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('projects', 'code')->ignore($this->project?->id),
            ],
            'nom' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'clientId' => ['required_without:nouveauClient', 'nullable', 'exists:clients,id'],
            'nouveauClient' => ['required_without:clientId', 'nullable', 'string', 'max:255'],
            'nouveauClientSigle' => ['nullable', 'string', 'max:30'],
            'typeProjet' => ['required', Rule::enum(ProjectType::class)],
            'categorie' => ['required', Rule::enum(ProjectCategory::class)],
            'ordreComite' => ['required', 'integer', 'min:0', 'max:99'],
            'paysDomiciliation' => ['nullable', 'string', 'max:100'],
            'statut' => ['required', Rule::enum(ProjectStatus::class)],
            'dateDebut' => ['nullable', 'date'],
            'dateFinInitiale' => ['nullable', 'date', 'after_or_equal:dateDebut'],
            'dateFinRevisee' => ['nullable', 'date', 'after_or_equal:dateDebut'],
            'avancement' => ['required', 'numeric', 'min:0', 'max:100'],
            'tauxRealisation' => ['required', 'numeric', 'min:0', 'max:100'],
            'lienPlanning' => ['nullable', 'url', 'max:2048'],
            // Le filtre des listes déroulantes ne protège que l'écran : la
            // règle est revérifiée ici, où elle engage la donnée.
            'chefProjetId' => ['nullable', 'exists:users,id', $this->regleDuRole(MemberRole::ChefProjet)],
            'backUpId' => ['nullable', 'exists:users,id', $this->regleDuRole(MemberRole::BackUp)],
            'sponsorId' => ['nullable', 'exists:users,id', $this->regleDuRole(MemberRole::Sponsor)],
            'referentTechniqueId' => ['nullable', 'exists:users,id', $this->regleDuRole(MemberRole::ReferentTechnique)],
            // Un projet sans étape n'a pas de bandeau d'avancement, donc rien à
            // présenter au comité : au moins une est exigée.
            'etapes' => ['array', 'min:1'],
            'etapes.*' => ['integer', 'exists:workflow_steps,id'],
            'phases' => ['array', 'min:1'],
            'phases.*' => ['integer', 'exists:phases,id'],
        ], attributes: [
            'code' => 'code projet',
            'etapes' => 'étapes du projet',
            'phases' => 'phases du projet',
            'nom' => 'nom du projet',
            'clientId' => 'client',
            'nouveauClient' => 'nouveau client',
            'typeProjet' => 'type de projet',
            'categorie' => 'rubrique du comité',
            'ordreComite' => 'ordre de passage',
            'dateFinInitiale' => 'date de fin initiale',
            'dateFinRevisee' => 'date de fin révisée',
            'lienPlanning' => 'lien planning',
        ]);

        $clientId = $donnees['clientId'] ?: Client::firstOrCreate(
            ['nom' => $donnees['nouveauClient']],
            ['sigle' => $donnees['nouveauClientSigle'] ?: null, 'pays' => $donnees['paysDomiciliation'] ?: null],
        )->id;

        $attributs = [
            'code' => Str::upper($donnees['code']),
            'nom' => $donnees['nom'],
            'description' => $donnees['description'] ?: null,
            'client_id' => $clientId,
            'type_projet' => $donnees['typeProjet'],
            'categorie' => $donnees['categorie'],
            'ordre_comite' => $donnees['ordreComite'],
            'pays_domiciliation' => $donnees['paysDomiciliation'] ?: null,
            'statut' => $donnees['statut'],
            'date_debut' => $donnees['dateDebut'] ?: null,
            'date_fin_initiale' => $donnees['dateFinInitiale'] ?: null,
            'date_fin_revisee' => $donnees['dateFinRevisee'] ?: null,
            'avancement_pct' => $donnees['avancement'],
            'taux_realisation_pct' => $donnees['tauxRealisation'],
            'lien_planning' => $donnees['lienPlanning'] ?: null,
            'chef_projet_id' => $donnees['chefProjetId'] ?: null,
            'back_up_id' => $donnees['backUpId'] ?: null,
            'sponsor_id' => $donnees['sponsorId'] ?: null,
            'referent_technique_id' => $donnees['referentTechniqueId'] ?: null,
        ];

        $provisioner = app(ProjectProvisioner::class);

        if ($this->project) {
            $typeChange = $this->project->type_projet->value !== $attributs['type_projet'];

            Audit::pour('Modification de la fiche projet', fn () => $this->project->update($attributs));

            // Un changement de type refait le bandeau d'avancement propre au nouveau type.
            if ($typeChange) {
                $this->project->steps()->delete();
                $this->project->update(['workflow_step_id' => null]);
            }

            $provisioner->provisionner(
                $this->project->refresh(),
                new Perimetre(phases: $donnees['phases'], etapes: $donnees['etapes']),
            );

            // Ce qui subsiste malgré la décoche est ce que le provisioner a
            // refusé de retirer, du travail y étant consigné.
            $conservees = $provisioner->etapesConservees($this->project, $donnees['etapes'])->count()
                + $provisioner->phasesConservees($this->project, $donnees['phases'])->count();

            $this->etapes = $this->project->steps()->pluck('workflow_step_id')->all();
            $this->phases = $this->project->phases()->pluck('phase_id')->all();

            Flux::toast(variant: 'success', text: 'Projet mis à jour.');

            if ($conservees > 0) {
                Flux::toast(
                    variant: 'warning',
                    text: $conservees.' '.($conservees > 1 ? 'éléments écartés ont été conservés' : 'élément écarté a été conservé')
                        .' : du travail y est consigné.',
                );
            }
        } else {
            $this->project = $this->creerAvecUnCodeLibre($attributs);
            $provisioner->provisionner(
                $this->project,
                new Perimetre(phases: $donnees['phases'], etapes: $donnees['etapes']),
            );

            Flux::toast(variant: 'success', text: 'Projet créé : le référentiel MPM a été déroulé.');
        }

        $this->redirectRoute('projets.show', $this->project, navigate: true);
    }

    public function rendering(View $view): void
    {
        $view->title($this->project ? 'Modifier '.$this->project->nom : 'Nouveau projet');
    }
}; ?>

<div class="flex w-full flex-1 flex-col gap-6">
    <div>
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('projets.index')" wire:navigate>Projets</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $project ? $project->code : 'Nouveau' }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <flux:heading size="xl" class="mt-2">{{ $project ? 'Modifier le projet' : 'Nouveau projet' }}</flux:heading>
        <flux:subheading>
            @if ($project)
                Les 8 phases MPM et les livrables types restent alignés sur le référentiel.
            @else
                Les 8 phases MPM, le bandeau d'avancement et les livrables types seront créés automatiquement.
            @endif
        </flux:subheading>
    </div>

    <form wire:submit="enregistrer" class="grid gap-6 xl:grid-cols-3">
        <div class="flex flex-col gap-6 xl:col-span-2">
            <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading size="lg">Identification</flux:heading>

                <div class="mt-4 space-y-4">
                    <flux:input wire:model.blur="nom" label="Nom du projet" required />

                    <div class="grid gap-4 sm:grid-cols-2">
                        {{-- Le code est attribué par la suite MLP-0001, MLP-0002…
                             Il reste repris en main pour un projet dont le code
                             figure à un contrat ou vient d'un suivi antérieur. --}}
                        <div>
                            <flux:input
                                wire:model="code"
                                label="Code projet"
                                :readonly="! $codePersonnalise"
                                :variant="$codePersonnalise ? null : 'filled'"
                                required
                            />

                            <div class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                                @if ($codePersonnalise)
                                    @unless ($project)
                                        Code saisi à la main.
                                        <button type="button" wire:click="reprendreLeCodeAutomatique" class="underline hover:text-zinc-700 dark:hover:text-zinc-200">
                                            Revenir au code automatique
                                        </button>
                                    @endunless
                                @else
                                    Attribué automatiquement.
                                    <button type="button" wire:click="personnaliserLeCode" class="underline hover:text-zinc-700 dark:hover:text-zinc-200">
                                        Choisir un autre code
                                    </button>
                                @endif
                            </div>
                        </div>

                        <flux:select wire:model="typeProjet" label="Type de projet">
                            @foreach (ProjectType::cases() as $cas)
                                <flux:select.option :value="$cas->value">{{ $cas->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    <flux:textarea wire:model="description" label="Description" rows="3" />
                </div>
            </section>

            <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading size="lg">Client</flux:heading>

                <div class="mt-4 space-y-4">
                    <flux:select wire:model="clientId" label="Client existant" placeholder="Choisir un client…">
                        <flux:select.option value="">— Créer un nouveau client —</flux:select.option>
                        @foreach ($this->clients() as $client)
                            <flux:select.option :value="$client->id">{{ $client->displayName() }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    @if ($clientId === '')
                        <div class="grid gap-4 sm:grid-cols-2">
                            <flux:input wire:model="nouveauClient" label="Nom du nouveau client" />
                            <flux:input wire:model="nouveauClientSigle" label="Sigle" placeholder="BBCI" />
                        </div>
                    @endif

                    <flux:input wire:model="paysDomiciliation" label="Pays de domiciliation" placeholder="Côte d'Ivoire" />
                </div>
            </section>

            <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading size="lg">Délais &amp; avancement</flux:heading>

                <div class="mt-4 space-y-4">
                    <div class="grid gap-4 sm:grid-cols-3">
                        <flux:input wire:model="dateDebut" type="date" label="Date début" />
                        <flux:input wire:model="dateFinInitiale" type="date" label="Date fin initiale" />
                        <flux:input wire:model="dateFinRevisee" type="date" label="Date fin révisée" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input wire:model="avancement" type="number" step="0.01" min="0" max="100" label="% Avancement" />
                        <flux:input wire:model="tauxRealisation" type="number" step="0.01" min="0" max="100" label="Taux de réalisation" />
                    </div>

                    @if ($project)
                        {{-- Le taux constaté sur les cartes, proposé au chef de projet
                             plutôt que substitué à son jugement. --}}
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:button wire:click="reprendreDesCartes" variant="ghost" size="sm" icon="calculator">
                                Reprendre des cartes
                            </flux:button>
                            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                                Calcule les deux taux d'après les cartes du tableau, pondérées par leur charge.
                            </flux:text>
                        </div>
                    @endif

                    <flux:input wire:model="lienPlanning" type="url" label="Lien planning détaillé" placeholder="https://…" />
                </div>
            </section>
        </div>

        <div class="flex flex-col gap-6">
            <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading size="lg">Pilotage</flux:heading>
                <flux:subheading>Ces acteurs alimentent l'en-tête du flash report</flux:subheading>

                {{-- Chaque rôle ne propose que les personnes dont le profil le
                     prévoit : un sponsor n'apparaît pas comme référent technique. --}}
                <div class="mt-4 space-y-4">
                    @foreach ($this->rolesDePilotage() as $champ => $role)
                        @php $candidats = $this->candidats($role); @endphp

                        <div>
                            <flux:select wire:model="{{ $champ }}" :label="$role->label()" placeholder="Non assigné">
                                <flux:select.option value="">Non assigné</flux:select.option>
                                @foreach ($candidats as $utilisateur)
                                    <flux:select.option :value="$utilisateur->id">
                                        {{ $utilisateur->name }}@if ($utilisateur->poste) — {{ $utilisateur->poste }}@endif
                                    </flux:select.option>
                                @endforeach
                            </flux:select>

                            @if ($candidats->isEmpty())
                                <div class="mt-1.5 text-xs text-amber-600 dark:text-amber-400">
                                    Aucun compte n'a le profil requis. À créer depuis l'écran « Utilisateurs ».
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>

            {{-- Phases MPM : un correctif n'a pas de phase d'opportunité, un
                 déploiement interne pas de généralisation. --}}
            <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <flux:heading size="lg">Phases MPM</flux:heading>
                        <flux:subheading>
                            Le cycle suivi par ce projet, et les livrables qui vont avec
                        </flux:subheading>
                    </div>

                    <flux:badge size="sm" :color="count($phases) ? 'blue' : 'amber'">
                        {{ count($phases) }} / {{ $this->phasesDisponibles()->count() }}
                    </flux:badge>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-3 text-xs">
                    <button type="button" wire:click="cocherToutesLesPhases" class="text-zinc-500 underline hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">
                        Tout cocher
                    </button>
                    <button type="button" wire:click="decocherToutesLesPhases" class="text-zinc-500 underline hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">
                        Tout décocher
                    </button>
                </div>

                @error('phases')
                    <flux:text size="sm" class="mt-2 text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                @enderror

                <div class="mt-3 space-y-0.5">
                    @foreach ($this->phasesDisponibles() as $phase)
                        @php $consignee = $this->phasesIrretirables()->firstWhere('phase_id', $phase->id); @endphp

                        <label
                            wire:key="phase-{{ $phase->id }}"
                            class="flex cursor-pointer items-start gap-2.5 rounded-lg px-2 py-1.5 hover:bg-zinc-50 dark:hover:bg-zinc-800"
                        >
                            <input
                                type="checkbox"
                                wire:model.live="phases"
                                value="{{ $phase->id }}"
                                class="mt-0.5 size-4 shrink-0 rounded border-zinc-300 text-blue-600 dark:border-zinc-600 dark:bg-zinc-800"
                            />

                            <span class="min-w-0 flex-1 text-sm">
                                <span class="tabular-nums text-zinc-400">{{ str_pad((string) $phase->ordre, 2, '0', STR_PAD_LEFT) }}</span>
                                <span class="text-zinc-800 dark:text-zinc-200">{{ $phase->nom }}</span>

                                @if ($consignee)
                                    <span class="mt-0.5 block text-xs text-amber-600 dark:text-amber-400">
                                        Travail ou livrable consigné : la phase sera conservée même décochée.
                                    </span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>

                <flux:text size="sm" class="mt-3 text-zinc-500 dark:text-zinc-400">
                    Décocher une phase écarte aussi ses livrables types — sauf ceux déjà produits.
                </flux:text>
            </section>

            {{-- Étapes du bandeau d'avancement : tous les projets ne passent pas
                 par le pilote ni la généralisation. --}}
            <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <flux:heading size="lg">Étapes du projet</flux:heading>
                        <flux:subheading>
                            Le bandeau d'avancement, tel qu'il sera suivi et présenté au comité
                        </flux:subheading>
                    </div>

                    <flux:badge size="sm" :color="count($etapes) ? 'blue' : 'amber'">
                        {{ count($etapes) }} / {{ $this->etapesDisponibles()->count() }}
                    </flux:badge>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-3 text-xs">
                    <button type="button" wire:click="cocherToutesLesEtapes" class="text-zinc-500 underline hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">
                        Tout cocher
                    </button>
                    <button type="button" wire:click="decocherToutesLesEtapes" class="text-zinc-500 underline hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">
                        Tout décocher
                    </button>
                </div>

                @error('etapes')
                    <flux:text size="sm" class="mt-2 text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                @enderror

                <div class="mt-3 space-y-0.5">
                    @forelse ($this->etapesDisponibles() as $etape)
                        @php $consignee = $this->etapesIrretirables()->firstWhere('workflow_step_id', $etape->id); @endphp

                        <label
                            wire:key="etape-{{ $etape->id }}"
                            class="flex cursor-pointer items-start gap-2.5 rounded-lg px-2 py-1.5 hover:bg-zinc-50 dark:hover:bg-zinc-800"
                        >
                            <input
                                type="checkbox"
                                wire:model.live="etapes"
                                value="{{ $etape->id }}"
                                class="mt-0.5 size-4 shrink-0 rounded border-zinc-300 text-blue-600 dark:border-zinc-600 dark:bg-zinc-800"
                            />

                            <span class="min-w-0 flex-1 text-sm">
                                <span class="tabular-nums text-zinc-400">{{ str_pad((string) $etape->ordre, 2, '0', STR_PAD_LEFT) }}</span>
                                <span class="text-zinc-800 dark:text-zinc-200">{{ $etape->libelle }}</span>

                                @if ($consignee)
                                    <span class="mt-0.5 block text-xs text-amber-600 dark:text-amber-400">
                                        Du travail y est consigné : l'étape sera conservée même décochée.
                                    </span>
                                @endif
                            </span>
                        </label>
                    @empty
                        <flux:text size="sm" class="text-zinc-500">
                            Choisissez d'abord un type de projet.
                        </flux:text>
                    @endforelse
                </div>
            </section>

            <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading size="lg">Comité projets</flux:heading>
                <flux:subheading>Rubrique du sommaire et rang de passage</flux:subheading>

                <div class="mt-4 space-y-4">
                    <flux:select wire:model="categorie" label="Rubrique">
                        @foreach (ProjectCategory::ordonnees() as $cas)
                            <flux:select.option :value="$cas->value">
                                {{ str_pad((string) $cas->rang(), 2, '0', STR_PAD_LEFT) }} — {{ $cas->label() }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:input
                        wire:model="ordreComite"
                        type="number"
                        min="0"
                        max="99"
                        label="Ordre de passage dans la rubrique"
                        description="0 pour laisser le classement alphabétique décider"
                    />
                </div>
            </section>

            <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading size="lg">Statut</flux:heading>

                <flux:select wire:model="statut" label="Statut du projet" class="mt-4">
                    @foreach (ProjectStatus::cases() as $cas)
                        <flux:select.option :value="$cas->value">{{ $cas->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </section>

            <div class="flex gap-2">
                <flux:button type="submit" variant="primary" class="flex-1">
                    {{ $project ? 'Enregistrer' : 'Créer le projet' }}
                </flux:button>
                <flux:button
                    :href="$project ? route('projets.show', $project) : route('projets.index')"
                    variant="ghost"
                    wire:navigate
                >Annuler</flux:button>
            </div>

            @if ($project && auth()->user()->can('delete', $project))
                {{-- Zone de suppression. Séparée du reste, en rouge, et fermée
                     par la saisie du code : un projet arrivé à son terme se
                     clôture, il ne se supprime pas. --}}
                <section class="rounded-xl border border-red-200 bg-red-50/60 p-4 dark:border-red-900/60 dark:bg-red-950/20">
                    <flux:heading size="lg" class="text-red-800 dark:text-red-200">Supprimer le projet</flux:heading>

                    <p class="mt-2 text-sm leading-snug text-red-900/80 dark:text-red-200/80">
                        Phases, étapes, tâches, livrables, jalons, flash reports, pièces jointes et
                        journal disparaissent avec lui, sans retour possible.
                        <strong>Un projet terminé se clôture, un projet arrêté s'abandonne</strong> —
                        le statut, ci-dessus, les sort du portefeuille actif en gardant leur histoire.
                        La suppression n'est là que pour un projet créé par erreur.
                    </p>

                    <flux:input
                        wire:model="codeDeSuppression"
                        label="Saisissez {{ $project->code }} pour confirmer"
                        placeholder="{{ $project->code }}"
                        class="mt-3"
                    />

                    <flux:button
                        wire:click="supprimer"
                        wire:confirm="Supprimer définitivement ce projet et tout ce qui en dépend ?"
                        variant="danger"
                        icon="trash"
                        class="mt-3 w-full"
                    >
                        Supprimer définitivement
                    </flux:button>
                </section>
            @endif
        </div>
    </form>
</div>
