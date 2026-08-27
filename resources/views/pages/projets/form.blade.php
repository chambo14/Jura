<?php

use App\Enums\ProjectCategory;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Services\AvancementService;
use App\Services\ProjectCodeGenerator;
use App\Services\ProjectProvisioner;
use App\Support\Audit\Audit;
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

            return;
        }

        $this->authorize('create', Project::class);

        $this->typeProjet = ProjectType::Deploiement->value;
        $this->categorie = ProjectCategory::Autres->value;
        $this->statut = ProjectStatus::EnPreparation->value;
        $this->chefProjetId = (string) auth()->id();
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
            'chefProjetId' => ['nullable', 'exists:users,id'],
            'backUpId' => ['nullable', 'exists:users,id'],
            'sponsorId' => ['nullable', 'exists:users,id'],
            'referentTechniqueId' => ['nullable', 'exists:users,id'],
        ], attributes: [
            'code' => 'code projet',
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

            $provisioner->provisionner($this->project->refresh());

            Flux::toast(variant: 'success', text: 'Projet mis à jour.');
        } else {
            $this->project = $this->creerAvecUnCodeLibre($attributs);
            $provisioner->provisionner($this->project);

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

                <div class="mt-4 space-y-4">
                    @foreach ([
                        'chefProjetId' => 'Chef de projet',
                        'backUpId' => 'Back Up',
                        'sponsorId' => 'Sponsor',
                        'referentTechniqueId' => 'Référent technique',
                    ] as $champ => $label)
                        <flux:select wire:model="{{ $champ }}" :label="$label" placeholder="Non assigné">
                            <flux:select.option value="">Non assigné</flux:select.option>
                            @foreach ($this->utilisateurs() as $utilisateur)
                                <flux:select.option :value="$utilisateur->id">{{ $utilisateur->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endforeach
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
        </div>
    </form>
</div>
