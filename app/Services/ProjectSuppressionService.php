<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Suppression d'un projet, et de ce qui n'aurait pas disparu tout seul.
 *
 * Les tables liées s'effacent en cascade — phases, étapes, tâches, livrables,
 * jalons, flash reports, journal. Les fichiers déposés, eux, vivent sur le
 * disque : leur ligne en base disparaît avec le projet, mais le fichier reste,
 * sans plus rien pour dire à quoi il servait ni comment le retrouver. Sur un
 * hébergement mutualisé, ces orphelins s'accumulent jusqu'à saturer le quota.
 */
class ProjectSuppressionService
{
    public function supprimer(Project $project): void
    {
        DB::transaction(function () use ($project) {
            $this->effacerLesFichiers($project);

            $project->delete();
        });
    }

    private function effacerLesFichiers(Project $project): void
    {
        $project->documents()->get()->each(function (Attachment $piece) {
            Storage::disk($piece->disque)->delete($piece->chemin);
        });
    }
}
