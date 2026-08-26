<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\User;

/**
 * Une pièce jointe n'a pas de droits propres : elle suit ceux du projet qui
 * la porte. Qui voit le projet télécharge le fichier ; qui contribue au
 * projet en dépose et retire.
 */
class AttachmentPolicy
{
    public function view(User $user, Attachment $attachment): bool
    {
        return $user->can('view', $attachment->project);
    }

    /**
     * Retirer un fichier déposé par quelqu'un d'autre demande de pouvoir
     * contribuer au projet ; chacun peut en revanche retirer le sien.
     */
    public function delete(User $user, Attachment $attachment): bool
    {
        if (! $user->actif) {
            return false;
        }

        return $user->can('contribute', $attachment->project)
            || $attachment->depose_par_id === $user->id;
    }
}
