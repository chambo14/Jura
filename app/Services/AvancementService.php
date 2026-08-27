<?php

namespace App\Services;

use App\Enums\ProgressStatus;
use App\Models\Task;
use App\Support\Avancement\AvancementEquipe;
use App\Support\Avancement\PointDAvancement;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * Mesure l'avancement à partir des cartes du tableau, équipe par équipe.
 *
 * Le taux d'avancement d'un projet est ailleurs une valeur saisie par le chef
 * de projet. Ici il est *constaté* : il ne dit pas ce qu'on pense avoir fait,
 * mais ce que les cartes montrent — ce qui permet de comparer les deux.
 *
 * Les règles de comptage, choisies une fois et appliquées partout :
 *
 *  - une carte pèse sa charge en jours ; sans charge saisie, elle pèse une
 *    journée, faute de quoi les cartes non chiffrées disparaîtraient du
 *    calcul au lieu d'y compter pour peu ;
 *  - une carte annulée ne pèse ni au numérateur ni au dénominateur : elle est
 *    sortie du périmètre, pas ratée ;
 *  - une carte est réalisée le jour où elle est terminée. À défaut de date de
 *    réalisation saisie, sa dernière modification en tient lieu — approximatif,
 *    mais préférable à une carte close qui n'apparaîtrait jamais sur la courbe.
 */
class AvancementService
{
    /** Longueur de la courbe d'évolution, en semaines. */
    public const SEMAINES = 8;

    /** Intitulé de la bande qui regroupe les cartes sans équipe identifiée. */
    public const SANS_EQUIPE = 'Sans équipe';

    /**
     * Une ligne par équipe, l'équipe étant celle de la personne à qui la carte
     * est assignée. Les équipes nommées d'abord, les cartes orphelines ensuite.
     *
     * @param  Collection<int, Task>  $cartes
     * @return Collection<int, AvancementEquipe>
     */
    public function parEquipe(Collection $cartes, int $semaines = self::SEMAINES): Collection
    {
        return $cartes
            ->groupBy(fn (Task $carte) => $carte->assignee?->equipe ?: self::SANS_EQUIPE)
            ->map(fn (Collection $lot, string $equipe) => $this->pour($equipe, $lot, $semaines))
            ->sortBy(fn (AvancementEquipe $ligne) => ($ligne->equipe === self::SANS_EQUIPE ? '1' : '0').$ligne->equipe)
            ->values();
    }

    /**
     * @param  Collection<int, Task>  $cartes
     */
    public function pour(string $equipe, Collection $cartes, int $semaines = self::SEMAINES): AvancementEquipe
    {
        [$totale, $faite] = $this->charges($cartes, null);

        $retenues = $cartes->reject(fn (Task $carte) => $carte->statut === ProgressStatus::Annule);

        return new AvancementEquipe(
            equipe: $equipe,
            taux: $totale > 0 ? round($faite / $totale * 100, 1) : 0.0,
            tauxDeclare: $this->tauxDeclare($cartes),
            cartes: $retenues->count(),
            terminees: $retenues->where('statut', ProgressStatus::Termine)->count(),
            enRetard: $retenues->filter(fn (Task $carte) => $carte->enRetard())->count(),
            chargeTotale: $totale,
            chargeFaite: $faite,
            evolution: $this->evolution($cartes, $semaines),
        );
    }

    /**
     * Part de la charge effectivement close, à aujourd'hui ou à une date passée.
     *
     * @param  Collection<int, Task>  $cartes
     */
    public function taux(Collection $cartes, ?CarbonInterface $ala = null): float
    {
        [$totale, $faite] = $this->charges($cartes, $ala);

        return $totale > 0 ? round($faite / $totale * 100, 1) : 0.0;
    }

    /**
     * Avancement tel que déclaré sur les cartes : une carte terminée vaut
     * 100 %, les autres valent le pourcentage saisi dessus.
     *
     * @param  Collection<int, Task>  $cartes
     */
    public function tauxDeclare(Collection $cartes): float
    {
        $totale = 0.0;
        $faite = 0.0;

        foreach ($cartes as $carte) {
            if ($carte->statut === ProgressStatus::Annule) {
                continue;
            }

            $poids = $this->poids($carte);
            $totale += $poids;
            $faite += $poids * ($carte->statut === ProgressStatus::Termine ? 100.0 : $carte->avancement_pct) / 100;
        }

        return $totale > 0 ? round($faite / $totale * 100, 1) : 0.0;
    }

    /**
     * Le taux réalisé recalculé de semaine en semaine, le dernier point étant
     * aujourd'hui. Une carte entrée dans le périmètre après la date visée n'y
     * figure pas : ouvrir un lot de cartes fait donc reculer la courbe, comme
     * il se doit.
     *
     * Les semaines où l'équipe n'avait encore aucune carte sont retirées : y
     * tracer un zéro ferait lire un retard là où il n'y avait rien à faire.
     *
     * @param  Collection<int, Task>  $cartes
     * @return Collection<int, PointDAvancement>
     */
    public function evolution(Collection $cartes, int $semaines = self::SEMAINES): Collection
    {
        $maintenant = Date::now();

        return collect(range(max($semaines, 2) - 1, 0))
            ->map(function (int $recul) use ($cartes, $maintenant) {
                $date = $maintenant->copy()->subWeeks($recul);
                [$totale, $faite] = $this->charges($cartes, $date);

                return $totale > 0
                    ? new PointDAvancement($date, round($faite / $totale * 100, 1))
                    : null;
            })
            ->filter()
            ->values();
    }

    /**
     * Charge retenue et charge réalisée à une date donnée.
     *
     * @param  Collection<int, Task>  $cartes
     * @return array{0: float, 1: float}
     */
    private function charges(Collection $cartes, ?CarbonInterface $ala): array
    {
        $totale = 0.0;
        $faite = 0.0;

        foreach ($cartes as $carte) {
            if ($carte->statut === ProgressStatus::Annule) {
                continue;
            }

            if ($ala !== null && $this->dateDEntree($carte)->greaterThan($ala)) {
                continue;
            }

            $poids = $this->poids($carte);
            $totale += $poids;

            $cloture = $this->dateDeCloture($carte);

            if ($cloture !== null && ($ala === null || $cloture->lessThanOrEqualTo($ala))) {
                $faite += $poids;
            }
        }

        return [$totale, $faite];
    }

    /**
     * Date à laquelle la carte est entrée dans le périmètre.
     *
     * C'est sa création, sauf pour une carte reprise d'un suivi antérieur :
     * ses dates de travail précèdent alors sa saisie, et s'en tenir à la
     * création ferait croire que tout le périmètre est apparu le jour de la
     * reprise — une courbe plate à zéro, puis un mur.
     */
    private function dateDEntree(Task $carte): CarbonInterface
    {
        $dates = collect([
            $carte->date_debut,
            $carte->date_echeance,
            $carte->date_realisation,
            $carte->created_at,
        ])->filter();

        return $dates->min() ?? Date::now();
    }

    /** Une carte sans charge saisie pèse une journée. */
    private function poids(Task $carte): float
    {
        return (float) ($carte->charge_jours ?: 1);
    }

    private function dateDeCloture(Task $carte): ?CarbonInterface
    {
        if ($carte->statut !== ProgressStatus::Termine) {
            return null;
        }

        return $carte->date_realisation ?? $carte->updated_at;
    }
}
