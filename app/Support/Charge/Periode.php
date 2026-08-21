<?php

namespace App\Support\Charge;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * Une fenêtre de temps sur laquelle se calcule la charge : un mois ou une semaine.
 */
final readonly class Periode
{
    public function __construct(
        public CarbonInterface $debut,
        public CarbonInterface $fin,
        public string $libelle,
        public string $libelleCourt,
    ) {}

    public static function mois(CarbonInterface $reference): self
    {
        $debut = $reference->startOfMonth();

        return new self(
            debut: $debut,
            fin: $debut->endOfMonth(),
            libelle: $debut->translatedFormat('F Y'),
            libelleCourt: $debut->translatedFormat('M y'),
        );
    }

    public static function semaine(CarbonInterface $reference): self
    {
        $debut = $reference->startOfWeek(CarbonInterface::MONDAY);

        return new self(
            debut: $debut,
            fin: $debut->addDays(6),
            libelle: 'Semaine du '.$debut->translatedFormat('j F Y'),
            libelleCourt: 'S'.$debut->isoWeek(),
        );
    }

    /**
     * Suite de périodes consécutives à partir d'aujourd'hui.
     *
     * @return array<int, self>
     */
    public static function suite(string $granularite, int $nombre): array
    {
        $periodes = [];
        $curseur = Date::now();

        for ($i = 0; $i < $nombre; $i++) {
            $periodes[] = $granularite === 'semaine'
                ? self::semaine($curseur)
                : self::mois($curseur);

            $curseur = $granularite === 'semaine'
                ? $curseur->addWeek()
                : $curseur->addMonth();
        }

        return $periodes;
    }

    public function jours(): int
    {
        return (int) $this->debut->diffInDays($this->fin) + 1;
    }

    /**
     * Nombre de jours ouvrés, base de conversion des charges exprimées en jours.
     */
    public function joursOuvres(): int
    {
        $ouvres = 0;
        $curseur = $this->debut;

        while ($curseur->lte($this->fin)) {
            if (! $curseur->isWeekend()) {
                $ouvres++;
            }

            $curseur = $curseur->addDay();
        }

        return max(1, $ouvres);
    }

    /**
     * Part de la période couverte par une fenêtre, entre 0 et 1.
     */
    public function recouvrement(?CarbonInterface $debut, ?CarbonInterface $fin): float
    {
        $debut = $debut ?? $this->debut;
        $fin = $fin ?? $this->fin;

        if ($fin->lt($this->debut) || $debut->gt($this->fin)) {
            return 0.0;
        }

        $depuis = $debut->max($this->debut);
        $jusqua = $fin->min($this->fin);

        return round(((int) $depuis->diffInDays($jusqua) + 1) / $this->jours(), 4);
    }

    public function contient(CarbonInterface $date): bool
    {
        return $date->betweenIncluded($this->debut, $this->fin);
    }
}
