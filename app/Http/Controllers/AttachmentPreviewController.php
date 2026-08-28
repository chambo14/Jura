<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sert un fichier pour lecture directe dans le navigateur.
 *
 * Le téléchargement oblige à sortir de l'application pour consulter une note
 * de cadrage ; la lecture la montre sur place. Elle a un prix : le fichier
 * s'affiche alors dans l'origine de l'application, où un document actif —
 * une page HTML, un SVG — pourrait exécuter du script avec la session de
 * celui qui le lit. D'où trois précautions qui ne se négocient pas :
 *
 *   1. seuls les formats inertes sont servis en lecture, jamais le HTML ni
 *      le SVG, quand bien même le dépôt les accepte ;
 *   2. le type est établi à partir du contenu réel du fichier sur le disque,
 *      jamais à partir de ce qu'annonçait le navigateur au dépôt ;
 *   3. les en-têtes interdisent au navigateur de renifler un autre type et
 *      à la page de charger ou d'exécuter quoi que ce soit.
 */
class AttachmentPreviewController extends Controller
{
    /**
     * Formats qu'un navigateur sait rendre sans rien exécuter.
     *
     * @var array<int, string>
     */
    private const LISIBLES = [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        'image/bmp',
        'text/plain',
    ];

    public function __invoke(Attachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $attachment);

        abort_unless($attachment->existe(), 404);

        $disque = Storage::disk($attachment->disque);
        $type = $disque->mimeType($attachment->chemin) ?: '';
        $type = trim(explode(';', $type)[0]);

        // Un fichier dont le contenu ne correspond à aucun format inerte n'est
        // pas affiché : il reste téléchargeable, ce qui ne l'exécute nulle part.
        abort_unless(in_array($type, self::LISIBLES, true), 404);

        if ($type === 'text/plain') {
            $type = 'text/plain; charset=UTF-8';
        }

        return $disque->response($attachment->chemin, $attachment->nom, [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox",
            'Referrer-Policy' => 'no-referrer',
        ], 'inline');
    }
}
