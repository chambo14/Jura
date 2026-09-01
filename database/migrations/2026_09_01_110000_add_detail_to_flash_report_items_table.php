<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le détail de la tâche, recopié sur la ligne du flash report.
 *
 * La ligne portait le libellé, la date et un mot de statut. Qui en est chargé
 * et où elle en est manquaient — au comité, ce sont les deux premières
 * questions posées. Les valeurs sont recopiées au moment de la préparation
 * plutôt que lues sur la tâche : un rapport publié doit rester ce qu'il disait
 * le jour où il a été rendu, même si la tâche a bougé depuis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flash_report_items', function (Blueprint $table) {
            $table->string('responsable')->nullable()->after('libelle');
            $table->decimal('avancement_pct', 5, 2)->nullable()->after('responsable');
        });
    }

    public function down(): void
    {
        Schema::table('flash_report_items', function (Blueprint $table) {
            $table->dropColumn(['responsable', 'avancement_pct']);
        });
    }
};
