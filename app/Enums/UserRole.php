<?php

namespace App\Enums;

/**
 * Profils d'accès livrés avec l'application. Ils servent de définition initiale
 * de la table `profiles` ; à l'exécution, les droits sont toujours lus depuis
 * le profil rattaché à l'utilisateur, que la Direction peut faire évoluer.
 */
enum UserRole: string
{
    case Direction = 'direction';
    case ChefProjet = 'chef_projet';
    case Sponsor = 'sponsor';
    case Membre = 'membre';

    public function label(): string
    {
        return match ($this) {
            self::Direction => 'Direction des Projets',
            self::ChefProjet => 'Chef de projet',
            self::Sponsor => 'Sponsor',
            self::Membre => "Membre d'équipe",
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Direction => "Voit l'intégralité du portefeuille, crée et modifie tout projet, administre les comptes.",
            self::ChefProjet => 'Crée des projets, pilote ceux dont il est chef de projet ou back-up, rédige les flash reports.',
            self::Sponsor => 'Consultation seule des projets sur lesquels il est rattaché.',
            self::Membre => 'Met à jour les tâches et les livrables des projets sur lesquels il est affecté.',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Direction => 'purple',
            self::ChefProjet => 'blue',
            self::Sponsor => 'amber',
            self::Membre => 'zinc',
        };
    }

    public function ordre(): int
    {
        return match ($this) {
            self::Direction => 1,
            self::ChefProjet => 2,
            self::Sponsor => 3,
            self::Membre => 4,
        };
    }

    /**
     * Droits accordés par défaut à ce profil.
     *
     * @return array<string, bool>
     */
    public function permissions(): array
    {
        $aucune = array_fill_keys(Permission::codes(), false);

        return match ($this) {
            self::Direction => array_fill_keys(Permission::codes(), true),
            self::ChefProjet => [...$aucune,
                Permission::CreeProjet->value => true,
                Permission::PiloteProjet->value => true,
                Permission::Contribue->value => true,
                Permission::RedigeFlashReport->value => true,
            ],
            self::Sponsor => $aucune,
            self::Membre => [...$aucune, Permission::Contribue->value => true],
        };
    }
}
