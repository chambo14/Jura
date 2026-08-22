<?php

namespace App\Services;

use App\Enums\HealthStatus;
use App\Models\Project;
use App\Support\Alert;
use App\Support\ProjectHealth;
use Illuminate\Support\Collection;

/**
 * Calcule les dérives d'un projet à partir de ses dates, de son avancement,
 * de ses jalons, de ses tâches et de ses livrables obligatoires.
 */
class ProjectHealthService
{
    /**
     * Écart toléré entre avancement déclaré et avancement théorique avant alerte.
     */
    public const SEUIL_ECART_ORANGE = 5.0;

    public const SEUIL_ECART_ROUGE = 15.0;

    /**
     * Glissement de date de fin à partir duquel le projet passe en alerte.
     */
    public const SEUIL_GLISSEMENT_ORANGE = 15;

    public const SEUIL_GLISSEMENT_ROUGE = 45;

    public function diagnostiquer(Project $project): ProjectHealth
    {
        /** @var Collection<int, Alert> $alertes */
        $alertes = collect();

        $glissement = $project->glissementJours();
        $theorique = $project->avancementTheoriquePct();
        $ecart = $theorique === null ? null : round($project->avancement_pct - $theorique, 2);

        if (! $project->statut->isActive()) {
            return new ProjectHealth(HealthStatus::Vert, $alertes, $glissement, $theorique, $ecart);
        }

        $this->verifierGlissement($project, $glissement, $alertes);
        $this->verifierEcheance($project, $alertes);
        $this->verifierAvancement($ecart, $alertes);
        $this->verifierCoherenceRealisation($project, $alertes);
        $this->verifierJalons($project, $alertes);
        $this->verifierTaches($project, $alertes);
        $this->verifierLivrables($project, $alertes);

        return new ProjectHealth(
            statut: $this->consolider($alertes),
            alertes: $alertes,
            glissementJours: $glissement,
            avancementTheoriquePct: $theorique,
            ecartAvancementPct: $ecart,
        );
    }

    /**
     * @param  Collection<int, Alert>  $alertes
     */
    private function verifierGlissement(Project $project, int $glissement, Collection $alertes): void
    {
        if ($glissement < self::SEUIL_GLISSEMENT_ORANGE) {
            return;
        }

        $detail = sprintf(
            'fin initiale %s, fin révisée %s',
            $project->date_fin_initiale?->format('d/m/Y') ?? '—',
            $project->date_fin_revisee?->format('d/m/Y') ?? '—',
        );

        $alertes->push($glissement >= self::SEUIL_GLISSEMENT_ROUGE
            ? Alert::rouge('glissement', "Glissement de {$glissement} jours sur la date de fin", $detail)
            : Alert::orange('glissement', "Glissement de {$glissement} jours sur la date de fin", $detail));
    }

    /**
     * @param  Collection<int, Alert>  $alertes
     */
    private function verifierEcheance(Project $project, Collection $alertes): void
    {
        $restants = $project->joursRestants();

        if ($restants === null) {
            $alertes->push(Alert::orange('sans_echeance', 'Aucune date de fin renseignée'));

            return;
        }

        if ($restants < 0) {
            $alertes->push(Alert::rouge(
                'echeance_depassee',
                'Échéance dépassée de '.abs($restants).' jours',
                'échéance au '.$project->dateFinEffective()?->format('d/m/Y'),
            ));
        } elseif ($restants <= 14 && $project->avancement_pct < 90) {
            $alertes->push(Alert::orange(
                'echeance_proche',
                "Échéance dans {$restants} jours avec un avancement de ".$this->pct($project->avancement_pct),
            ));
        }
    }

    /**
     * @param  Collection<int, Alert>  $alertes
     */
    private function verifierAvancement(?float $ecart, Collection $alertes): void
    {
        if ($ecart === null || $ecart >= -self::SEUIL_ECART_ORANGE) {
            return;
        }

        $retard = abs($ecart);
        $libelle = 'Retard de '.$this->pct($retard).' sur l\'avancement attendu à date';

        $alertes->push($retard >= self::SEUIL_ECART_ROUGE
            ? Alert::rouge('avancement', $libelle)
            : Alert::orange('avancement', $libelle));
    }

    /**
     * Un taux de réalisation nettement inférieur à l'avancement déclaré signale
     * un avancement optimiste par rapport au travail effectivement produit.
     *
     * @param  Collection<int, Alert>  $alertes
     */
    private function verifierCoherenceRealisation(Project $project, Collection $alertes): void
    {
        $ecart = $project->avancement_pct - $project->taux_realisation_pct;

        if ($ecart > self::SEUIL_ECART_ROUGE) {
            $alertes->push(Alert::orange(
                'coherence_realisation',
                'Avancement déclaré supérieur de '.$this->pct($ecart).' au taux de réalisation',
                $this->pct($project->avancement_pct).' vs '.$this->pct($project->taux_realisation_pct),
            ));
        }
    }

    /**
     * @param  Collection<int, Alert>  $alertes
     */
    private function verifierJalons(Project $project, Collection $alertes): void
    {
        $enRetard = $project->milestones->filter(fn ($jalon) => $jalon->enRetard());

        if ($enRetard->isEmpty()) {
            return;
        }

        $critiques = $enRetard->where('critique', true);
        $detail = $enRetard->take(3)->map(fn ($jalon) => $jalon->libelle)->implode(' · ');

        $alertes->push($critiques->isNotEmpty()
            ? Alert::rouge('jalons', $critiques->count().' jalon(s) critique(s) en retard', $detail)
            : Alert::orange('jalons', $enRetard->count().' jalon(s) en retard', $detail));
    }

    /**
     * @param  Collection<int, Alert>  $alertes
     */
    private function verifierTaches(Project $project, Collection $alertes): void
    {
        $enRetard = $project->tasks->filter(fn ($tache) => $tache->enRetard());

        if ($enRetard->isEmpty()) {
            return;
        }

        $detail = $enRetard->take(3)->map(fn ($tache) => $tache->libelle)->implode(' · ');

        $alertes->push($enRetard->count() >= 5
            ? Alert::rouge('taches', $enRetard->count().' tâches en retard', $detail)
            : Alert::orange('taches', $enRetard->count().' tâche(s) en retard', $detail));
    }

    /**
     * @param  Collection<int, Alert>  $alertes
     */
    private function verifierLivrables(Project $project, Collection $alertes): void
    {
        $enRetard = $project->deliverables->filter(fn ($livrable) => $livrable->enRetard());

        if ($enRetard->isEmpty()) {
            return;
        }

        $obligatoires = $enRetard->where('obligatoire', true);
        $detail = $enRetard->take(3)->map(fn ($livrable) => $livrable->nom)->implode(' · ');

        $alertes->push($obligatoires->isNotEmpty()
            ? Alert::rouge('livrables', $obligatoires->count().' livrable(s) obligatoire(s) en retard', $detail)
            : Alert::orange('livrables', $enRetard->count().' livrable(s) en retard', $detail));
    }

    /**
     * @param  Collection<int, Alert>  $alertes
     */
    private function consolider(Collection $alertes): HealthStatus
    {
        if ($alertes->contains(fn (Alert $alerte) => $alerte->severite === HealthStatus::Rouge)) {
            return HealthStatus::Rouge;
        }

        return $alertes->isEmpty() ? HealthStatus::Vert : HealthStatus::Orange;
    }

    private function pct(float $valeur): string
    {
        return number_format($valeur, 1, ',', ' ').' %';
    }
}
