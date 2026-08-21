<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\FlashReport;
use App\Models\User;

class FlashReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->actif;
    }

    public function view(User $user, FlashReport $report): bool
    {
        return app(ProjectPolicy::class)->view($user, $report->project);
    }

    /**
     * Le flash report est rédigé par un profil habilité, sur un projet qu'il pilote.
     */
    public function create(User $user, FlashReport $report): bool
    {
        return $user->peut(Permission::RedigeFlashReport)
            && app(ProjectPolicy::class)->update($user, $report->project);
    }

    public function update(User $user, FlashReport $report): bool
    {
        return $report->statut->isEditable() && $this->create($user, $report);
    }

    /**
     * Publier fige les chiffres présentés au comité : réservé au pilotage du projet.
     */
    public function publish(User $user, FlashReport $report): bool
    {
        return $this->create($user, $report);
    }

    /**
     * Dépublier pour corriger une erreur reste une prérogative de la Direction.
     */
    public function unpublish(User $user, FlashReport $report): bool
    {
        return $user->actif
            && $user->voitToutLePortefeuille()
            && $user->peut(Permission::RedigeFlashReport);
    }

    public function delete(User $user, FlashReport $report): bool
    {
        return $this->update($user, $report);
    }
}
