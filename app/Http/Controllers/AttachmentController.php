<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /**
     * Sert un fichier déposé. Il n'existe aucune URL publique vers le disque :
     * chaque téléchargement repasse par le contrôle d'accès du projet.
     */
    public function __invoke(Attachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $attachment);

        abort_unless($attachment->existe(), 404);

        return Storage::disk($attachment->disque)->download($attachment->chemin, $attachment->nom);
    }
}
