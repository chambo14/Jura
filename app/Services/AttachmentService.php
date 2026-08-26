<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Deliverable;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Dépôt et retrait des pièces jointes.
 *
 * Les fichiers vont sur un disque privé : rien n'est servi directement par
 * le serveur web, tout passe par une route qui vérifie l'accès au projet.
 * Le nom d'origine est conservé en base pour l'affichage et le
 * téléchargement, jamais utilisé comme nom de fichier sur le disque.
 */
class AttachmentService
{
    public function deposer(Model $cible, UploadedFile $fichier, User $auteur, ?string $description = null): Attachment
    {
        $project = $this->projetDe($cible);
        $disque = (string) config('documents.disque', 'local');

        $chemin = $fichier->storeAs(
            'projets/'.$project->id,
            Str::uuid()->toString().'.'.$this->extension($fichier),
            ['disk' => $disque],
        );

        return Attachment::create([
            'project_id' => $project->id,
            'attachable_type' => $cible->getMorphClass(),
            'attachable_id' => $cible->getKey(),
            'depose_par_id' => $auteur->id,
            'nom' => $this->nomLisible($fichier),
            'chemin' => $chemin,
            'disque' => $disque,
            'mime' => $fichier->getClientMimeType(),
            'taille' => $fichier->getSize() ?: 0,
            'description' => $description ?: null,
        ]);
    }

    /**
     * Retire la ligne et le fichier. L'ordre compte : si l'effacement du
     * fichier échoue, la pièce jointe reste visible plutôt que de laisser
     * une entrée qui ne mène nulle part.
     */
    public function supprimer(Attachment $attachment): void
    {
        Storage::disk($attachment->disque)->delete($attachment->chemin);

        $attachment->delete();
    }

    /**
     * Le projet qui porte le contrôle d'accès du fichier.
     */
    public function projetDe(Model $cible): Project
    {
        return match (true) {
            $cible instanceof Project => $cible,
            $cible instanceof Task, $cible instanceof Deliverable => $cible->project,
            default => throw new InvalidArgumentException(
                'Type de rattachement non pris en charge : '.$cible::class,
            ),
        };
    }

    /**
     * Extension issue du contenu réel quand elle est reconnue, sinon celle
     * du nom fourni par le navigateur — jamais un chemin.
     */
    private function extension(UploadedFile $fichier): string
    {
        $extension = $fichier->extension() ?: $fichier->getClientOriginalExtension();

        return Str::lower(preg_replace('/[^A-Za-z0-9]/', '', $extension) ?: 'bin');
    }

    private function nomLisible(UploadedFile $fichier): string
    {
        $nom = trim(basename($fichier->getClientOriginalName()));

        return $nom !== '' ? Str::limit($nom, 250, '') : 'document';
    }
}
