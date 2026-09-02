<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Des étapes de cycle propres à un projet.
 *
 * Le bandeau « PHASE ACTUELLE DU PROJET » ne pouvait afficher que des étapes
 * du référentiel MPM. Les dossiers de comité montrent pourtant que chaque
 * projet dessine son propre parcours : e-ZiKash suit ses tests SBS et son
 * autorisation BRB, PI BAGRI ses homologations BCEAO — aucun de ces jalons
 * n'existe dans un référentiel commun, et n'a de raison d'y entrer.
 *
 * Une étape peut donc désormais n'appartenir qu'à un projet : sans référence
 * au référentiel, avec son libellé à elle. Une étape issue du référentiel peut
 * de son côté être renommée pour ce projet sans toucher au référentiel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_steps', function (Blueprint $table) {
            $table->foreignId('workflow_step_id')->nullable()->change();
            $table->string('libelle')->nullable()->after('workflow_step_id');
        });
    }

    public function down(): void
    {
        Schema::table('project_steps', function (Blueprint $table) {
            $table->dropColumn('libelle');
        });
    }
};
