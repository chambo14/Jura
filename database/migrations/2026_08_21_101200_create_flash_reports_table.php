<?php

use App\Enums\FlashReportStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un flash report fige l'état du projet pour une semaine donnée : les chiffres
     * sont recopiés à la publication pour que la présentation reste fidèle a posteriori.
     */
    public function up(): void
    {
        Schema::create('flash_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();

            $table->date('semaine_du');
            $table->date('semaine_au');
            $table->string('statut')->default(FlashReportStatus::Brouillon->value);

            // Instantané du bloc "PLANNING & AVANCEMENT".
            $table->date('date_debut')->nullable();
            $table->date('date_fin_initiale')->nullable();
            $table->date('date_fin_revisee')->nullable();
            $table->decimal('avancement_pct', 5, 2)->default(0);
            $table->decimal('taux_realisation_pct', 5, 2)->default(0);

            $table->foreignId('workflow_step_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('phase_id')->nullable()->constrained()->nullOnDelete();

            $table->text('synthese')->nullable();
            $table->string('lien_planning')->nullable();
            $table->string('sante')->nullable();
            $table->timestamp('publie_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'semaine_du']);
            $table->index(['semaine_du', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flash_reports');
    }
};
