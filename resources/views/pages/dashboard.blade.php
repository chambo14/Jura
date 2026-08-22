<?php

use App\Enums\FlashReportStatus;
use App\Enums\HealthStatus;
use App\Enums\ProgressStatus;
use App\Models\FlashReport;
use App\Models\Milestone;
use App\Models\Project;
use App\Services\ChargeService;
use App\Services\FlashReportBuilder;
use App\Services\PortfolioAnalyticsService;
use App\Services\ProjectHealthService;
use App\Support\Charge\ChargeCollaborateur;
use App\Support\Analyse\LigneAxe;
use App\Support\Charge\Periode;
use App\Support\ProjectHealth;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Tableau de bord')] class extends Component {
    use WithPagination;

    /**
     * Horizon des échéances à surveiller, en jours.
     */
    private const HORIZON_JOURS = 15;

    /**
     * Nombre de projets affichés par page dans le tableau de suivi.
     */
    private const PAR_PAGE = 10;

    /**
     * Nombre d'échéances montrées avant dépliage.
     */
    private const ECHEANCES_VISIBLES = 5;

    /**
     * La liste des échéances est-elle dépliée au-delà des premières ?
     */
    public bool $echeancesDepliees = false;

    /**
     * L'horizon de quinze jours est-il levé, pour voir toutes les échéances ouvertes ?
     */
    public bool $echeancesSansHorizon = false;

    public function deplierEcheances(): void
    {
        $this->echeancesDepliees = ! $this->echeancesDepliees;

        $this->oublierEcheances();
    }

    public function basculerHorizonEcheances(): void
    {
        $this->echeancesSansHorizon = ! $this->echeancesSansHorizon;

        // Les échéances que l'on découvre en levant l'horizon sont les plus
        // lointaines, donc en fin de liste : sans déplier, le clic n'aurait
        // aucun effet visible.
        $this->echeancesDepliees = $this->echeancesSansHorizon;

        $this->oublierEcheances();
    }

    private function oublierEcheances(): void
    {
        unset($this->echeances, $this->echeancesVisibles, $this->echeancesMasquees);
    }

    /**
     * Portefeuille visible, classé du plus préoccupant au plus serein.
     *
     * @return Collection<int, array{projet: Project, sante: ProjectHealth}>
     */
    #[Computed]
    public function portefeuille(): Collection
    {
        $service = app(ProjectHealthService::class);

        return Project::query()
            ->visibleTo(auth()->user())
            ->actifs()
            ->with(['client', 'chefProjet', 'phase', 'workflowStep', 'milestones', 'tasks', 'deliverables'])
            ->get()
            ->map(fn (Project $projet) => [
                'projet' => $projet,
                'sante' => $service->diagnostiquer($projet),
            ])
            ->sortByDesc(fn (array $ligne) => $ligne['sante']->priorite())
            ->values();
    }

    /**
     * Page courante du portefeuille. Le classement dépendant du diagnostic,
     * il est calculé en mémoire puis découpé, et non paginé en base.
     *
     * @return LengthAwarePaginator<int, array{projet: Project, sante: ProjectHealth}>
     */
    #[Computed]
    public function pagePortefeuille(): LengthAwarePaginator
    {
        $tout = $this->portefeuille();
        $page = max(1, (int) $this->getPage());

        return new LengthAwarePaginator(
            $tout->forPage($page, self::PAR_PAGE)->values(),
            $tout->count(),
            self::PAR_PAGE,
            $page,
            ['path' => request()->url()],
        );
    }

    #[Computed]
    public function lundi(): CarbonInterface
    {
        return FlashReportBuilder::lundiDe();
    }

    /**
     * @return Collection<int, FlashReport>
     */
    #[Computed]
    public function rapportsSemaine(): Collection
    {
        return FlashReport::query()
            ->whereIn('project_id', $this->portefeuille()->pluck('projet.id'))
            ->pourSemaine($this->lundi())
            ->get()
            ->keyBy('project_id');
    }

    /**
     * Répartition du portefeuille par état de santé, dans l'ordre d'affichage.
     *
     * @return Collection<int, array{statut: HealthStatus, nombre: int, part: float}>
     */
    #[Computed]
    public function sante(): Collection
    {
        $total = max(1, $this->portefeuille()->count());

        return collect([HealthStatus::Rouge, HealthStatus::Orange, HealthStatus::Vert])
            ->map(function (HealthStatus $statut) use ($total) {
                $nombre = $this->portefeuille()->filter(
                    fn (array $ligne) => $ligne['sante']->statut === $statut,
                )->count();

                return ['statut' => $statut, 'nombre' => $nombre, 'part' => round($nombre / $total * 100, 2)];
            });
    }

    /**
     * @return array<string, float|int>
     */
    #[Computed]
    public function avancement(): array
    {
        $portefeuille = $this->portefeuille();
        $theoriques = $portefeuille
            ->map(fn (array $ligne) => $ligne['sante']->avancementTheoriquePct)
            ->filter(fn (?float $valeur) => $valeur !== null);

        return [
            'reel' => round($portefeuille->avg(fn (array $l) => $l['projet']->avancement_pct) ?? 0, 1),
            'attendu' => $theoriques->isEmpty() ? 0.0 : round($theoriques->avg(), 1),
        ];
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function couverture(): array
    {
        $rapports = $this->rapportsSemaine();

        return [
            'attendus' => $this->portefeuille()->count(),
            'publies' => $rapports->where('statut', FlashReportStatus::Publie)->count(),
            'brouillons' => $rapports->where('statut', FlashReportStatus::Brouillon)->count(),
        ];
    }

    /**
     * Projets sans flash report rédigé pour la semaine en cours.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function rapportsManquants(): Collection
    {
        return $this->portefeuille()
            ->reject(fn (array $ligne) => $this->rapportsSemaine()->has($ligne['projet']->id))
            ->map(fn (array $ligne) => $ligne['projet'])
            ->values();
    }

    /**
     * Jalons dépassés ou à échoir dans l'horizon, les plus urgents d'abord.
     *
     * @return Collection<int, Milestone>
     */
    #[Computed]
    public function echeances(): Collection
    {
        return Milestone::query()
            ->whereIn('project_id', $this->portefeuille()->pluck('projet.id'))
            ->whereNotIn('statut', [ProgressStatus::Termine->value, ProgressStatus::Annule->value])
            ->when(
                ! $this->echeancesSansHorizon,
                fn (Builder $q) => $q->whereDate('date_prevue', '<=', now()->addDays(self::HORIZON_JOURS)->toDateString()),
            )
            ->with('project')
            ->orderBy('date_prevue')
            ->get();
    }

    /**
     * Échéances effectivement montrées, selon que la liste est dépliée ou non.
     *
     * @return Collection<int, Milestone>
     */
    #[Computed]
    public function echeancesVisibles(): Collection
    {
        return $this->echeancesDepliees
            ? $this->echeances()
            : $this->echeances()->take(self::ECHEANCES_VISIBLES);
    }

    /**
     * Combien d'échéances le repli laisse de côté.
     */
    #[Computed]
    public function echeancesMasquees(): int
    {
        return max(0, $this->echeances()->count() - self::ECHEANCES_VISIBLES);
    }

    /**
     * Collaborateurs en surcharge sur le mois courant, affectation nominale
     * pondérée et tâches confiées confondues.
     *
     * @return Collection<int, ChargeCollaborateur>
     */
    #[Computed]
    public function surcharges(): Collection
    {
        return app(ChargeService::class)
            ->pourPeriode(Periode::mois(Date::now()))
            ->filter(fn (ChargeCollaborateur $c) => $c->enSurcharge())
            ->values();
    }

    /**
     * Regroupements qui concentrent le plus de glissement, sur les deux axes.
     *
     * Résumé volontairement court : trois lignes par axe, le détail complet
     * étant l'affaire de l'écran d'analyse.
     *
     * @return array<string, Collection<int, LigneAxe>>
     */
    #[Computed]
    public function derives(): array
    {
        $service = app(PortfolioAnalyticsService::class);
        $utilisateur = auth()->user();

        return [
            'Par client' => $service->parAxe(PortfolioAnalyticsService::AXE_CLIENT, $utilisateur)->take(3),
            'Par chef de projet' => $service->parAxe(PortfolioAnalyticsService::AXE_CHEF, $utilisateur)->take(3),
        ];
    }
}; ?>

<div class="flex w-full flex-1 flex-col gap-6">
    {{-- ───────────────────────────── En-tête ───────────────────────────── --}}
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <flux:heading size="xl">Portefeuille projets</flux:heading>
            <flux:subheading>
                Semaine du {{ $this->lundi()->translatedFormat('j F') }}
                au {{ $this->lundi()->addDays(4)->translatedFormat('j F Y') }}
            </flux:subheading>
        </div>

        <div class="flex gap-2">
            <flux:button :href="route('flash-reports.index')" icon="bolt" variant="filled" size="sm" wire:navigate>
                Flash reports
            </flux:button>
            <flux:button :href="route('comite')" icon="presentation-chart-bar" variant="primary" size="sm" wire:navigate>
                Mode comité
            </flux:button>
        </div>
    </div>

    {{-- ──────────────────── Les trois chiffres qui comptent ──────────────────── --}}
    <div class="grid gap-4 lg:grid-cols-3">
        {{-- Santé : une barre empilée vaut mieux qu'un compteur --}}
        <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-baseline justify-between gap-2">
                <h2 class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Santé du portefeuille</h2>
                <span class="text-sm tabular-nums text-zinc-400">{{ $this->portefeuille()->count() }} projets actifs</span>
            </div>

            <div class="mt-3 flex h-2.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                @foreach ($this->sante() as $tranche)
                    @if ($tranche['nombre'] > 0)
                        <div
                            @class([
                                'h-full',
                                'bg-red-500' => $tranche['statut'] === App\Enums\HealthStatus::Rouge,
                                'bg-amber-500' => $tranche['statut'] === App\Enums\HealthStatus::Orange,
                                'bg-green-500' => $tranche['statut'] === App\Enums\HealthStatus::Vert,
                            ])
                            style="width: {{ $tranche['part'] }}%"
                        ></div>
                    @endif
                @endforeach
            </div>

            <ul class="mt-3 space-y-1">
                @foreach ($this->sante() as $tranche)
                    <li class="flex items-center gap-2 text-sm">
                        <span @class([
                            'size-2.5 shrink-0 rounded-full',
                            'bg-red-500' => $tranche['statut'] === App\Enums\HealthStatus::Rouge,
                            'bg-amber-500' => $tranche['statut'] === App\Enums\HealthStatus::Orange,
                            'bg-green-500' => $tranche['statut'] === App\Enums\HealthStatus::Vert,
                        ])></span>
                        <span class="flex-1 text-zinc-600 dark:text-zinc-300">{{ $tranche['statut']->label() }}</span>
                        <span class="font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $tranche['nombre'] }}</span>
                    </li>
                @endforeach
            </ul>
        </section>

        {{-- Avancement : le chiffre seul ne dit rien, il faut l'attendu --}}
        <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <h2 class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Avancement moyen</h2>

            <div class="mt-2 flex items-baseline gap-2">
                <span class="text-3xl font-semibold tabular-nums text-zinc-900 dark:text-white">
                    {{ number_format($this->avancement()['reel'], 1, ',', ' ') }} %
                </span>
                @php $ecart = $this->avancement()['reel'] - $this->avancement()['attendu']; @endphp
                <span @class([
                    'text-sm font-medium tabular-nums',
                    'text-red-600 dark:text-red-400' => $ecart < 0,
                    'text-green-600 dark:text-green-400' => $ecart >= 0,
                ])>
                    {{ $ecart >= 0 ? '+' : '' }}{{ number_format($ecart, 1, ',', ' ') }} pts
                </span>
            </div>

            <x-progress-bar
                class="mt-3"
                :value="$this->avancement()['reel']"
                :target="$this->avancement()['attendu']"
                color="green"
                :show-value="false"
            />

            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                Attendu à date au prorata du calendrier :
                <span class="font-medium">{{ number_format($this->avancement()['attendu'], 1, ',', ' ') }} %</span>
            </p>
        </section>

        {{-- Couverture des flash reports --}}
        <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <h2 class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Flash reports de la semaine</h2>

            <div class="mt-2 flex items-baseline gap-2">
                <span class="text-3xl font-semibold tabular-nums text-zinc-900 dark:text-white">
                    {{ $this->couverture()['publies'] }}
                </span>
                <span class="text-sm text-zinc-500 dark:text-zinc-400">
                    publié(s) sur {{ $this->couverture()['attendus'] }} attendus
                </span>
            </div>

            <x-progress-bar
                class="mt-3"
                :value="$this->couverture()['attendus'] ? $this->couverture()['publies'] / $this->couverture()['attendus'] * 100 : 0"
                color="violet"
                :show-value="false"
            />

            <div class="mt-2 flex flex-wrap gap-x-4 text-xs text-zinc-500 dark:text-zinc-400">
                <span>{{ $this->couverture()['brouillons'] }} en brouillon</span>
                <span>{{ $this->rapportsManquants()->count() }} à rédiger</span>
            </div>
        </section>
    </div>

    {{-- ─────────────── Projets, du plus préoccupant au plus serein ─────────────── --}}
    <div>
        <div>
            <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                <div class="flex flex-wrap items-baseline gap-2">
                    <flux:heading size="lg">Projets à traiter en priorité</flux:heading>
                    <flux:text size="sm">
                        {{ $this->pagePortefeuille()->firstItem() ?? 0 }}–{{ $this->pagePortefeuille()->lastItem() ?? 0 }}
                        sur {{ $this->pagePortefeuille()->total() }}
                    </flux:text>
                </div>
                <flux:link :href="route('projets.index')" class="text-sm" wire:navigate>Voir tous les projets</flux:link>
            </div>

            <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800/60 dark:text-zinc-400">
                        <tr>
                            <th class="px-3 py-2 text-start font-medium">Projet</th>
                            <th class="px-3 py-2 text-start font-medium">Chef de projet</th>
                            <th class="px-3 py-2 text-start font-medium">Début</th>
                            <th class="px-3 py-2 text-start font-medium">Fin</th>
                            <th class="px-3 py-2 text-start font-medium">Évolution</th>
                            <th class="px-3 py-2 text-start font-medium">Statut</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                        @forelse ($this->pagePortefeuille() as $ligne)
                            @php
                                $projet = $ligne['projet'];
                                $sante = $ligne['sante'];
                                $motif = $sante->alertePrincipale();
                                $restants = $projet->joursRestants();
                                $rapport = $this->rapportsSemaine()->get($projet->id);
                            @endphp

                            @php $delaiExpire = $restants !== null && $restants < 0; @endphp

                            <tr wire:key="projet-{{ $projet->id }}" class="align-top transition hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                {{-- Nom du projet --}}
                                <td class="px-3 py-3">
                                    <a href="{{ route('projets.show', $projet) }}" wire:navigate class="font-medium text-zinc-900 hover:underline dark:text-white">
                                        {{ $projet->nom }}
                                    </a>
                                    <div class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ $projet->client->sigle ?? $projet->client->nom }}
                                    </div>
                                </td>

                                {{-- Chef de projet --}}
                                <td class="px-3 py-3">
                                    @if ($projet->chefProjet)
                                        <div class="flex items-center gap-2">
                                            <flux:avatar :name="$projet->chefProjet->name" size="xs" />
                                            <span class="text-xs text-zinc-700 dark:text-zinc-200">{{ $projet->chefProjet->name }}</span>
                                        </div>
                                    @else
                                        <span class="text-xs text-zinc-400">Non désigné</span>
                                    @endif
                                </td>

                                {{-- Date de début --}}
                                <td class="px-3 py-3 whitespace-nowrap text-xs tabular-nums text-zinc-600 dark:text-zinc-300">
                                    {{ $projet->date_debut?->format('d/m/Y') ?? '—' }}
                                </td>

                                {{-- Date de fin : celle qui fait foi, avec le reste à courir --}}
                                <td class="px-3 py-3 whitespace-nowrap">
                                    <div @class([
                                        'text-xs tabular-nums',
                                        'font-medium text-red-600 dark:text-red-400' => $delaiExpire,
                                        'text-zinc-700 dark:text-zinc-200' => ! $delaiExpire,
                                    ])>
                                        {{ $projet->dateFinEffective()?->format('d/m/Y') ?? 'À définir' }}
                                    </div>
                                    @if ($restants !== null)
                                        <div @class([
                                            'text-xs tabular-nums',
                                            'text-red-600 dark:text-red-400' => $delaiExpire,
                                            'text-amber-600 dark:text-amber-400' => ! $delaiExpire && $restants <= 14,
                                            'text-zinc-400' => ! $delaiExpire && $restants > 14,
                                        ])>
                                            {{ $delaiExpire ? '+'.abs($restants).' j' : $restants.' j' }}
                                        </div>
                                    @endif
                                </td>

                                {{-- Évolution --}}
                                <td class="w-40 px-3 py-3">
                                    <x-progress-bar
                                        :value="$projet->avancement_pct"
                                        :target="$sante->avancementTheoriquePct"
                                        color="green"
                                    />
                                    @if ($sante->avancementTheoriquePct !== null)
                                        @php $ecartProjet = $projet->avancement_pct - $sante->avancementTheoriquePct; @endphp
                                        <div @class([
                                            'mt-1 text-xs tabular-nums',
                                            'text-red-600 dark:text-red-400' => $ecartProjet < 0,
                                            'text-green-600 dark:text-green-400' => $ecartProjet >= 0,
                                        ])>
                                            {{ $ecartProjet >= 0 ? '+' : '' }}{{ number_format($ecartProjet, 1, ',', ' ') }} pts / attendu
                                        </div>
                                    @endif
                                </td>

                                {{-- Statut : délai expiré et alerte sont deux signaux distincts --}}
                                <td class="px-3 py-3">
                                    <div class="flex flex-wrap gap-1">
                                        @if ($delaiExpire)
                                            <flux:badge color="red" size="sm">Délai expiré</flux:badge>
                                        @endif
                                        <flux:badge :color="$sante->statut->color()" size="sm">
                                            {{ $sante->statut->label() }}
                                        </flux:badge>
                                    </div>

                                    @if ($motif)
                                        <div class="mt-1 text-xs leading-snug text-zinc-600 dark:text-zinc-300">
                                            {{ $motif->libelle }}
                                            @if ($sante->nombreAlertes() > 1)
                                                <span class="text-zinc-400">+ {{ $sante->nombreAlertes() - 1 }}</span>
                                            @endif
                                        </div>
                                    @else
                                        <div class="mt-1 text-xs text-zinc-400">Aucune dérive détectée</div>
                                    @endif

                                    <div class="mt-1.5">
                                        @if ($rapport)
                                            <flux:badge :color="$rapport->statut->color()" size="sm" :href="route('flash-reports.edit', $rapport)">
                                                Flash report {{ Str::lower($rapport->statut->label()) }}
                                            </flux:badge>
                                        @else
                                            <flux:badge color="red" size="sm" :href="route('projets.show', $projet).'?onglet=rapports'">
                                                Flash report à rédiger
                                            </flux:badge>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-12 text-center text-zinc-500 dark:text-zinc-400">
                                    Aucun projet actif ne vous est rattaché.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>

                @if ($this->pagePortefeuille()->hasPages())
                    <div class="border-t border-zinc-200 bg-white px-4 py-2 dark:border-zinc-700 dark:bg-zinc-900">
                        <flux:pagination :paginator="$this->pagePortefeuille()" />
                    </div>
                @endif
            </div>

            {{-- Légende : rien ne doit rester à deviner --}}
            <div class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-zinc-500 dark:text-zinc-400">
                <span class="flex items-center gap-1.5">
                    <span class="h-2 w-6 rounded-full bg-green-500"></span> Avancement déclaré
                </span>
                <span class="flex items-center gap-1.5">
                    <span class="h-3 w-0.5 bg-zinc-900 dark:bg-white"></span> Avancement attendu à date
                </span>
                <span><span class="font-medium">Délai expiré</span> : la date de fin qui fait foi est dépassée.</span>
                <span>Les projets sont classés du plus préoccupant au plus serein.</span>
            </div>
        </div>
    </div>

    {{-- ─────────────────────── Ce qui se joue cette semaine ─────────────────────── --}}
    <div>
        <div class="grid items-start gap-6 lg:grid-cols-3">
            @if ($this->rapportsManquants()->isNotEmpty())
                <section class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/60 dark:bg-amber-950/30">
                    <h2 class="flex items-center gap-2 font-medium text-amber-900 dark:text-amber-200">
                        <flux:icon name="bolt" variant="mini" />
                        {{ $this->rapportsManquants()->count() }} flash report(s) à rédiger
                    </h2>
                    <p class="mt-1 text-sm text-amber-800/80 dark:text-amber-200/70">
                        Avant le comité de la semaine du {{ $this->lundi()->translatedFormat('j F') }}.
                    </p>

                    <ul class="mt-3 space-y-1">
                        @foreach ($this->rapportsManquants()->take(5) as $projet)
                            <li>
                                <a href="{{ route('projets.show', $projet).'?onglet=rapports' }}" wire:navigate class="block truncate text-sm text-amber-900 hover:underline dark:text-amber-100">
                                    {{ $projet->nom }}
                                </a>
                            </li>
                        @endforeach
                    </ul>

                    <flux:button :href="route('flash-reports.index')" variant="filled" size="sm" class="mt-3 w-full" wire:navigate>
                        Ouvrir l'écran Flash reports
                    </flux:button>
                </section>
            @endif

            <section>
                <flux:heading size="lg">Prochaines échéances</flux:heading>
                <flux:subheading class="mb-3">
                    {{ $echeancesSansHorizon
                        ? 'Toutes les échéances ouvertes du portefeuille'
                        : 'Jalons dépassés ou à échoir sous 15 jours' }}
                </flux:subheading>

                @if ($this->echeances()->isEmpty())
                    <flux:callout
                        icon="check-circle"
                        color="green"
                        :heading="$echeancesSansHorizon ? 'Aucune échéance ouverte' : 'Aucune échéance dans les 15 jours'"
                    />
                @else
                    {{-- Dépliée, la liste peut compter des dizaines de lignes : on la
                         borne en hauteur pour que le reste du tableau de bord reste atteignable. --}}
                    <ul @class([
                        'divide-y divide-zinc-100 rounded-xl border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700',
                        'overflow-hidden' => ! $echeancesDepliees,
                        'max-h-96 overflow-y-auto' => $echeancesDepliees,
                    ])>
                        @foreach ($this->echeancesVisibles() as $jalon)
                            @php $retard = $jalon->enRetard(); @endphp

                            <li class="flex items-start gap-3 bg-white px-3 py-2.5 dark:bg-zinc-900">
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm leading-snug text-zinc-800 dark:text-zinc-100">
                                        {{ $jalon->libelle }}
                                        @if ($jalon->critique)
                                            <span class="text-xs font-medium text-red-600 dark:text-red-400">· critique</span>
                                        @endif
                                    </div>
                                    <a href="{{ route('projets.show', $jalon->project) }}" wire:navigate class="text-xs text-zinc-500 hover:underline dark:text-zinc-400">
                                        {{ $jalon->project->nom }}
                                    </a>
                                </div>

                                <div class="shrink-0 text-end">
                                    <div class="text-xs tabular-nums text-zinc-600 dark:text-zinc-300">
                                        {{ $jalon->date_prevue->format('d/m/Y') }}
                                    </div>
                                    <div @class([
                                        'text-xs',
                                        'font-medium text-red-600 dark:text-red-400' => $retard,
                                        'text-zinc-400' => ! $retard,
                                    ])>
                                        {{ $retard ? 'dépassée' : 'à venir' }}
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                        <div>
                            @if ($this->echeancesMasquees() > 0)
                                <flux:button
                                    wire:click="deplierEcheances"
                                    size="xs"
                                    variant="ghost"
                                    :icon="$echeancesDepliees ? 'chevron-up' : 'chevron-down'"
                                >
                                    {{ $echeancesDepliees ? 'Voir moins' : 'Voir plus ('.$this->echeancesMasquees().')' }}
                                </flux:button>
                            @endif
                        </div>

                        <flux:button wire:click="basculerHorizonEcheances" size="xs" variant="ghost">
                            {{ $echeancesSansHorizon ? 'Limiter à 15 jours' : 'Toutes les échéances' }}
                        </flux:button>
                    </div>
                @endif
            </section>

            <section>
                <div class="mb-1 flex items-baseline justify-between gap-2">
                    <flux:heading size="lg">Ressources en surcharge</flux:heading>
                    <flux:link :href="route('plan-de-charge.index')" class="text-sm" wire:navigate>Plan de charge</flux:link>
                </div>
                <flux:subheading class="mb-3">Charge du mois, affectations et tâches confiées</flux:subheading>

                @if ($this->surcharges()->isEmpty())
                    <flux:callout icon="check-circle" color="green" heading="Personne au-delà de 100 % ce mois-ci" />
                @else
                    <ul class="divide-y divide-zinc-100 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700">
                        @foreach ($this->surcharges()->take(5) as $charge)
                            <li class="flex items-center gap-3 bg-white px-3 py-2.5 dark:bg-zinc-900">
                                <span class="min-w-0 flex-1">
                                    <a href="{{ route('plan-de-charge.show', $charge->collaborateur) }}" wire:navigate class="block truncate text-sm text-zinc-800 hover:underline dark:text-zinc-100">
                                        {{ $charge->collaborateur->name }}
                                    </a>
                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ $charge->nombreProjets() }} projet(s)
                                        @if ($charge->deleguee() > 0)
                                            · dont {{ number_format($charge->deleguee(), 0) }} % délégué
                                        @endif
                                    </span>
                                </span>
                                <span class="shrink-0 text-sm font-semibold tabular-nums text-red-600 dark:text-red-400">
                                    {{ number_format($charge->total(), 0) }} %
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>
    {{-- Lecture décisionnelle : où la dérive se concentre. Détail sur l'écran d'analyse. --}}
    <div>
        <div class="mb-1 flex items-baseline justify-between gap-2">
            <flux:heading size="lg">Analyse du portefeuille</flux:heading>
            <flux:link :href="route('analyse')" class="text-sm" wire:navigate>Voir l'analyse</flux:link>
        </div>
        <flux:subheading class="mb-3">Glissement moyen de la date de fin, du regroupement le plus dérivant au moins dérivant</flux:subheading>

        <div class="grid gap-6 lg:grid-cols-2">
            @foreach ($this->derives() as $titre => $lignes)
                <section wire:key="derive-{{ Str::slug($titre) }}">
                    <flux:text size="sm" class="mb-2 font-medium">{{ $titre }}</flux:text>

                    @if ($lignes->isEmpty())
                        <flux:callout icon="check-circle" color="green" heading="Aucun glissement constaté" />
                    @else
                        @php $echelle = max(1, $lignes->first()->glissementMoyen); @endphp

                        <ul class="divide-y divide-zinc-100 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700">
                            @foreach ($lignes as $ligne)
                                <li class="bg-white px-3 py-2.5 dark:bg-zinc-900">
                                    <div class="flex items-center gap-3">
                                        <span class="min-w-0 flex-1 truncate text-sm text-zinc-800 dark:text-zinc-100">
                                            {{ $ligne->libelle }}
                                        </span>
                                        <span @class([
                                            'shrink-0 text-sm font-semibold tabular-nums',
                                            'text-red-600 dark:text-red-400' => $ligne->couleurGlissement() === 'red',
                                            'text-amber-600 dark:text-amber-400' => $ligne->couleurGlissement() === 'amber',
                                            'text-green-600 dark:text-green-400' => $ligne->couleurGlissement() === 'green',
                                        ])>{{ number_format($ligne->glissementMoyen, 0, ',', ' ') }} j</span>
                                    </div>

                                    <div class="mt-1.5 flex items-center gap-2">
                                        <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                            <div
                                                @class([
                                                    'h-full rounded-full',
                                                    'bg-red-500' => $ligne->couleurGlissement() === 'red',
                                                    'bg-amber-500' => $ligne->couleurGlissement() === 'amber',
                                                    'bg-green-500' => $ligne->couleurGlissement() === 'green',
                                                ])
                                                style="width: {{ round($ligne->glissementMoyen / $echelle * 100, 1) }}%"
                                            ></div>
                                        </div>
                                        <span class="shrink-0 text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ $ligne->nombreProjets }} projet(s)@if ($ligne->effectifFaible()) *@endif
                                        </span>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endforeach
        </div>
    </div>
</div>
