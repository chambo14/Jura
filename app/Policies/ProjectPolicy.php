<?php

namespace App\Policies;

use App\Enums\MemberRole;
use App\Enums\Permission;
use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    /**
     * Tout utilisateur actif accède à la liste ; le périmètre est filtré par
     * Project::scopeVisibleTo().
     */
    public function viewAny(User $user): bool
    {
        return $user->actif;
    }

    public function view(User $user, Project $project): bool
    {
        return $user->actif && ($user->voitToutLePortefeuille() || $this->estRattache($user, $project));
    }

    public function create(User $user): bool
    {
        return $user->actif && $user->peut(Permission::CreeProjet);
    }

    /**
     * Le cadrage d'un projet (dates, périmètre, ressources) n'est modifiable que
     * par un profil habilité à piloter, et sur les projets qui le concernent.
     */
    public function update(User $user, Project $project): bool
    {
        if (! $user->actif || ! $user->peut(Permission::PiloteProjet)) {
            return false;
        }

        return $user->voitToutLePortefeuille()
            || in_array($user->id, [$project->chef_projet_id, $project->back_up_id], true);
    }

    /**
     * Faire vivre le contenu opérationnel — tâches, livrables, avancement —
     * sans toucher au cadrage.
     */
    public function contribute(User $user, Project $project): bool
    {
        if (! $user->actif) {
            return false;
        }

        if ($this->update($user, $project)) {
            return true;
        }

        return $user->peut(Permission::Contribue) && $this->estRattache($user, $project);
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->actif && $user->voitToutLePortefeuille() && $user->peut(Permission::PiloteProjet);
    }

    /**
     * L'utilisateur figure-t-il dans le pilotage ou dans l'équipe projet ?
     */
    private function estRattache(User $user, Project $project): bool
    {
        $pilotage = [
            $project->chef_projet_id,
            $project->back_up_id,
            $project->sponsor_id,
            $project->referent_technique_id,
        ];

        if (in_array($user->id, $pilotage, true)) {
            return true;
        }

        return $project->relationLoaded('members')
            ? $project->members->contains('user_id', $user->id)
            : $project->members()->where('user_id', $user->id)->exists();
    }

    /**
     * Rôles que l'utilisateur occupe sur le projet, pour affichage.
     *
     * @return array<int, MemberRole>
     */
    public function rolesSur(User $user, Project $project): array
    {
        return $project->members
            ->where('user_id', $user->id)
            ->pluck('role')
            ->all();
    }
}
