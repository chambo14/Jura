<?php

namespace App\Enums;

enum MemberRole: string
{
    case ChefProjet = 'chef_projet';
    case BackUp = 'back_up';
    case Sponsor = 'sponsor';
    case ReferentTechnique = 'referent_technique';
    case Testeur = 'testeur';
    case Developpeur = 'developpeur';
    case Integrateur = 'integrateur';
    case Formateur = 'formateur';
    case Contributeur = 'contributeur';
    case Autre = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::ChefProjet => 'Chef de projet',
            self::BackUp => 'Back Up',
            self::Sponsor => 'Sponsor',
            self::ReferentTechnique => 'Référent technique',
            self::Testeur => 'Testeur',
            self::Developpeur => 'Développeur',
            self::Integrateur => 'Intégrateur',
            self::Formateur => 'Formateur',
            self::Contributeur => 'Contributeur',
            self::Autre => 'Autre',
        };
    }

    /**
     * Part d'un temps plein qu'un rôle consomme lorsqu'il est affecté à 100 %.
     *
     * Un sponsor suit un projet en comité, il ne l'exécute pas : le compter
     * comme un développeur donnerait des plans de charge à 600 %.
     */
    public function poids(): int
    {
        return match ($this) {
            self::Sponsor => 5,
            self::BackUp => 15,
            self::ReferentTechnique => 25,
            self::Testeur => 30,
            self::Formateur => 30,
            self::ChefProjet => 40,
            self::Integrateur => 70,
            self::Developpeur => 80,
            self::Contributeur, self::Autre => 50,
        };
    }

    /**
     * Rôle attribué automatiquement quand une tâche est déléguée à quelqu'un
     * qui n'était pas encore rattaché au projet.
     */
    public static function parDelegation(): self
    {
        return self::Contributeur;
    }

    /**
     * Roles rendered in the "PILOTAGE" block of the flash report, in display order.
     *
     * @return array<int, self>
     */
    public static function pilotage(): array
    {
        return [self::ChefProjet, self::BackUp, self::Sponsor, self::ReferentTechnique, self::Testeur];
    }

    public function color(): string
    {
        return match ($this) {
            self::ChefProjet => 'blue',
            self::BackUp => 'sky',
            self::Sponsor => 'amber',
            self::ReferentTechnique => 'violet',
            self::Testeur => 'teal',
            default => 'zinc',
        };
    }
}
