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

    /**
     * Profils d'accès dont les titulaires peuvent tenir ce rôle sur un projet.
     *
     * Les listes déroulantes du bloc « Pilotage » proposaient tout le monde :
     * un sponsor pouvait être désigné référent technique, et réciproquement.
     * Chaque rôle ne propose désormais que les personnes dont le profil le
     * prévoit — la Direction restant éligible partout, puisqu'elle supplée.
     *
     * Un tableau vide vaut absence de restriction : les rôles d'exécution
     * (testeur, développeur…) s'attribuent à qui de droit sans filtre.
     *
     * @return array<int, string>
     */
    public function profilsEligibles(): array
    {
        return match ($this) {
            self::ChefProjet, self::BackUp => [
                UserRole::Direction->value,
                UserRole::ChefProjet->value,
            ],
            self::Sponsor => [
                UserRole::Direction->value,
                UserRole::Sponsor->value,
            ],
            // Le référent technique est un membre d'équipe ; un chef de projet
            // peut aussi tenir ce rôle sur un projet qu'il ne pilote pas.
            self::ReferentTechnique => [
                UserRole::Direction->value,
                UserRole::ChefProjet->value,
                UserRole::Membre->value,
            ],
            default => [],
        };
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
