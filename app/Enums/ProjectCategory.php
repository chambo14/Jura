<?php

namespace App\Enums;

/**
 * Rubriques du sommaire du comité projets, telles qu'elles structurent
 * les dossiers de présentation existants.
 */
enum ProjectCategory: string
{
    case Monetique = 'monetique';
    case BanqueDigitale = 'banque_digitale';
    case PvRecette = 'pv_recette';
    case Autres = 'autres';

    public function label(): string
    {
        return match ($this) {
            self::Monetique => 'Projets monétiques',
            self::BanqueDigitale => 'Projets banques digitales',
            self::PvRecette => 'Projets avec réception de PV de recette',
            self::Autres => 'Autres types de projets',
        };
    }

    /**
     * Numéro de rubrique affiché sur les intercalaires du comité.
     */
    public function rang(): int
    {
        return match ($this) {
            self::Monetique => 1,
            self::BanqueDigitale => 2,
            self::PvRecette => 3,
            self::Autres => 4,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Monetique => 'indigo',
            self::BanqueDigitale => 'teal',
            self::PvRecette => 'green',
            self::Autres => 'zinc',
        };
    }

    /**
     * Rubriques dans l'ordre du sommaire.
     *
     * @return array<int, self>
     */
    public static function ordonnees(): array
    {
        $cases = self::cases();

        usort($cases, fn (self $a, self $b) => $a->rang() <=> $b->rang());

        return $cases;
    }
}
