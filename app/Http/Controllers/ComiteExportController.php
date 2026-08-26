<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Services\ComiteBuilder;
use App\Services\ComiteExporter;
use App\Services\FlashReportBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ComiteExportController extends Controller
{
    /**
     * Sert la présentation de la semaine au format PowerPoint.
     *
     * Le périmètre est celui de l'utilisateur : l'export ne contient que les
     * projets qu'il pourrait projeter à l'écran, ni plus ni moins.
     */
    public function __invoke(
        Request $requete,
        ComiteBuilder $builder,
        ComiteExporter $exporter,
    ): BinaryFileResponse {
        Gate::authorize('viewAny', Project::class);

        $requete->validate([
            'semaine' => ['nullable', 'date'],
            'brouillons' => ['nullable', 'boolean'],
        ]);

        $lundi = FlashReportBuilder::lundiDe(
            $requete->filled('semaine') ? Date::parse($requete->string('semaine')->toString()) : null,
        );

        /** @var User $utilisateur */
        $utilisateur = $requete->user();

        $rapports = $builder->rapports(
            $utilisateur,
            $lundi,
            $requete->boolean('brouillons', true),
        );

        abort_if($rapports->isEmpty(), 404, "Aucun flash report pour cette semaine : il n'y a rien à exporter.");

        $chemin = $exporter->exporter($builder->diapositives($rapports), $lundi);

        return response()
            ->download($chemin, $exporter->nomDuFichier($lundi))
            ->deleteFileAfterSend();
    }
}
