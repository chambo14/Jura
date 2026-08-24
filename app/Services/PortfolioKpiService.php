<?php

namespace App\Services;

use App\Enums\FlashReportStatus;
use App\Enums\HealthStatus;
use App\Models\FlashReport;
use App\Models\Project;
use App\Models\User;
use App\Support\Analyse\Kpi;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Bandeau d'indicateurs du portefeuille, en trois familles : les délais tenus,
 * la qualité du pilotage, et la discipline de processus.
 *
 * Les valeurs décrivent l'état du moment, lu sur les projets. Les tendances,
 * elles, viennent des flash reports : eux seuls conservent une trace
 * hebdomadaire, l'état d'un projet étant écrasé à chaque mise à jour. Les deux
 * sources ne coïncident pas toujours au dixième près — un projet sans flash
 * report de la semaine manque à l'agrégat — d'où la mention explicite portée
 * sous le bandeau.
 *
 * Lorsqu'aucun historique n'existe pour un indicateur, la tendance est laissée
 * vide plutôt que comblée par une approximation.
 */
class PortfolioKpiService
{
    public function __construct(private readonly PortfolioAnalyticsService $analyse) {}

    /**
     * @return Collection<int, Kpi>
     */
    public function pour(User $user): Collection
    {
        $synthese = $this->analyse->synthese($user);
        $actifs = $this->projetsActifs($user);
        $semaine = $this->agregats($user, FlashReportBuilder::lundiDe());
        $precedente = $this->agregats($user, FlashReportBuilder::lundiDe()->copy()->subWeek());

        $ecart = fn (string $cle) => $this->ecart($semaine, $precedente, $cle);
        $attendus = $actifs->count();
        $publies = $semaine['publies'];

        return collect([
            // --- Délais ---
            new Kpi(
                libelle: 'Glissement moyen',
                valeur: number_format($synthese->glissementMoyen, 0, ',', ' ').' j',
                sousTitre: 'pire cas : '.number_format($synthese->glissementMax, 0, ',', ' ').' j',
                icone: 'arrow-trending-up',
                couleur: 'red',
                tendance: $ecart('glissement'),
                uniteTendance: ' j',
                hausseFavorable: false,
                tendanceIndisponible: 'aucun flash report comparable',
            ),
            new Kpi(
                libelle: 'Projets ayant glissé',
                valeur: number_format($synthese->partProjetsGlisses(), 0, ',', ' ').' %',
                sousTitre: $synthese->projetsGlisses.' sur '.$synthese->projets,
                icone: 'calendar-days',
                couleur: 'amber',
                tendance: $ecart('partGlisses'),
                uniteTendance: ' pts',
                hausseFavorable: false,
            ),
            new Kpi(
                libelle: 'Jalons en retard',
                valeur: (string) $synthese->jalonsEnRetard,
                sousTitre: 'dont '.$synthese->jalonsCritiquesEnRetard.' critique(s)',
                icone: 'flag',
                couleur: 'red',
                hausseFavorable: false,
                tendanceIndisponible: 'les jalons ne sont pas historisés',
            ),

            // --- Pilotage ---
            new Kpi(
                libelle: 'Avancement moyen',
                valeur: number_format($actifs->avg('avancement_pct') ?? 0, 1, ',', ' ').' %',
                sousTitre: $attendus.' projet(s) actif(s)',
                icone: 'chart-bar',
                couleur: 'blue',
                tendance: $ecart('avancement'),
                uniteTendance: ' pts',
            ),
            new Kpi(
                libelle: 'Taux de réalisation',
                valeur: number_format($actifs->avg('taux_realisation_pct') ?? 0, 1, ',', ' ').' %',
                sousTitre: 'réalisé sur prévu, projets actifs',
                icone: 'check-circle',
                couleur: 'green',
                tendance: $ecart('realisation'),
                uniteTendance: ' pts',
            ),
            new Kpi(
                libelle: "Écart d'avancement",
                valeur: $synthese->ecartAvancementMoyen === null
                    ? '—'
                    : ($synthese->ecartAvancementMoyen >= 0 ? '+' : '')
                        .number_format($synthese->ecartAvancementMoyen, 1, ',', ' ').' pts',
                sousTitre: 'déclaré vs attendu à date',
                icone: 'scale',
                couleur: 'amber',
                tendanceIndisponible: "l'attendu à date n'est pas historisé",
            ),

            // --- Discipline de processus ---
            new Kpi(
                libelle: 'Projets déclarés au vert',
                valeur: $semaine['rapports'] === 0
                    ? '—'
                    : number_format($semaine['partVertes'], 0, ',', ' ').' %',
                sousTitre: 'santé déclarée au flash report',
                icone: 'shield-check',
                couleur: 'green',
                tendance: $ecart('partVertes'),
                uniteTendance: ' pts',
                tendanceIndisponible: 'aucun flash report cette semaine',
            ),
            new Kpi(
                libelle: 'Flash reports publiés',
                valeur: $publies.' / '.$attendus,
                sousTitre: $semaine['rapports'].' rédigé(s) cette semaine',
                icone: 'bolt',
                couleur: $publies === 0 ? 'red' : 'blue',
                tendance: $ecart('publies'),
                uniteTendance: '',
            ),
        ]);
    }

    /**
     * Projets actifs du périmètre visible.
     *
     * @return Collection<int, Project>
     */
    private function projetsActifs(User $user): Collection
    {
        return Project::query()->visibleTo($user)->actifs()->get();
    }

    /**
     * Agrégats des flash reports d'une semaine donnée, dans le périmètre visible.
     *
     * @return array<string, float|int>
     */
    private function agregats(User $user, CarbonInterface $lundi): array
    {
        $rapports = FlashReport::query()
            ->whereIn('project_id', Project::query()->visibleTo($user)->select('id'))
            ->pourSemaine($lundi)
            ->get();

        if ($rapports->isEmpty()) {
            return ['rapports' => 0, 'avancement' => 0.0, 'realisation' => 0.0,
                'glissement' => 0.0, 'partGlisses' => 0.0, 'partVertes' => 0.0, 'publies' => 0];
        }

        $glissements = $rapports->map(fn (FlashReport $r) => $this->glissement($r));

        return [
            'rapports' => $rapports->count(),
            'avancement' => round($rapports->avg('avancement_pct') ?? 0, 2),
            'realisation' => round($rapports->avg('taux_realisation_pct') ?? 0, 2),
            'glissement' => round($glissements->avg(), 2),
            'partGlisses' => round($glissements->filter(fn (int $j) => $j > 0)->count() / $rapports->count() * 100, 2),
            'partVertes' => round($rapports->where('sante', HealthStatus::Vert)->count() / $rapports->count() * 100, 2),
            'publies' => $rapports->where('statut', FlashReportStatus::Publie)->count(),
        ];
    }

    /**
     * Glissement constaté sur un flash report, en jours.
     */
    private function glissement(FlashReport $rapport): int
    {
        if (! $rapport->date_fin_initiale || ! $rapport->date_fin_revisee) {
            return 0;
        }

        return (int) $rapport->date_fin_initiale->diffInDays($rapport->date_fin_revisee, false);
    }

    /**
     * Écart d'un agrégat entre la semaine courante et la précédente.
     *
     * Null si l'une des deux semaines n'a aucun flash report : sans point de
     * comparaison, une variation ne veut rien dire.
     *
     * @param  array<string, float|int>  $semaine
     * @param  array<string, float|int>  $precedente
     */
    private function ecart(array $semaine, array $precedente, string $cle): ?float
    {
        if ($semaine['rapports'] === 0 || $precedente['rapports'] === 0) {
            return null;
        }

        return round($semaine[$cle] - $precedente[$cle], 1);
    }
}
