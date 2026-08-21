<?php

namespace App\Policies;

use App\Models\User;

/**
 * La gestion des comptes est réservée aux profils qui portent le droit
 * d'administration.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->actif && $user->gereLesUtilisateurs();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, User $cible): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Un administrateur ne peut pas se désactiver lui-même : il se couperait l'accès.
     */
    public function deactivate(User $user, User $cible): bool
    {
        return $this->viewAny($user) && $user->isNot($cible);
    }

    /**
     * Les comptes ne sont pas supprimés — ils sont désactivés, pour préserver
     * l'historique des projets et des flash reports auxquels ils sont rattachés.
     */
    public function delete(User $user, User $cible): bool
    {
        return false;
    }
}
