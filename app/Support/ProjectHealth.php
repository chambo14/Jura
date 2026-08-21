<?php

namespace App\Support;

use App\Enums\HealthStatus;
use Illuminate\Support\Collection;

/**
 * Résultat du diagnostic d'un projet : un statut global et le détail des dérives.
 */
final readonly class ProjectHealth
{
    /**
     * @param  Collection<int, Alert>  $alertes
     */
    public function __construct(
        public HealthStatus $statut,
        public Collection $alertes,
        public int $glissementJours,
        public ?float $avancementTheoriquePct,
        public ?float $ecartAvancementPct,
    ) {}

    /**
     * @return Collection<int, Alert>
     */
    public function alertesRouges(): Collection
    {
        return $this->alertes->where('severite', HealthStatus::Rouge)->values();
    }

    public function enAlerte(): bool
    {
        return $this->statut === HealthStatus::Rouge;
    }

    /**
     * Motifs classés par ce qu'ils appellent comme décision immédiate.
     * Le glissement de date arrive en dernier : c'est un constat sur le passé,
     * pas une action à mener cette semaine.
     *
     * @var array<int, string>
     */
    private const ORDRE_MOTIFS = [
        'echeance_depassee',
        'jalons',
        'livrables',
        'taches',
        'echeance_proche',
        'avancement',
        'coherence_realisation',
        'sans_echeance',
        'glissement',
    ];

    /**
     * Dérive à afficher comme motif dans les listes : la plus grave d'abord,
     * puis la plus actionnable parmi celles de même gravité.
     */
    public function alertePrincipale(): ?Alert
    {
        $candidates = $this->alertesRouges()->isNotEmpty() ? $this->alertesRouges() : $this->alertes;

        return $candidates
            ->sortBy(function (Alert $alerte) {
                $rang = array_search($alerte->code, self::ORDRE_MOTIFS, true);

                return $rang === false ? count(self::ORDRE_MOTIFS) : $rang;
            })
            ->first();
    }

    /**
     * Score de tri : la gravité d'abord, puis le nombre d'alertes rouges,
     * pour faire remonter les projets qui appellent une décision.
     */
    public function priorite(): int
    {
        return $this->statut->weight() * 100 + min(99, $this->alertesRouges()->count());
    }

    public function nombreAlertes(): int
    {
        return $this->alertes->count();
    }

    /**
     * Le projet avance-t-il moins vite que le calendrier ne l'exige ?
     */
    public function enRetardCalendaire(): bool
    {
        return $this->ecartAvancementPct !== null && $this->ecartAvancementPct < 0;
    }
}
