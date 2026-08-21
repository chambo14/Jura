<?php

use App\Enums\ProjectStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('nom');
            $table->text('description')->nullable();

            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('type_projet');
            $table->string('pays_domiciliation')->nullable();
            $table->string('statut')->default(ProjectStatus::EnPreparation->value);

            // Bloc "PLANNING & AVANCEMENT DU PROJET" du flash report.
            $table->date('date_debut')->nullable();
            $table->date('date_fin_initiale')->nullable();
            $table->date('date_fin_revisee')->nullable();
            $table->decimal('avancement_pct', 5, 2)->default(0);
            $table->decimal('taux_realisation_pct', 5, 2)->default(0);
            $table->string('lien_planning')->nullable();

            // Positions courantes dans les deux référentiels.
            $table->foreignId('phase_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('workflow_step_id')->nullable()->constrained()->nullOnDelete();

            // Pilotage : dénormalisé pour l'en-tête du flash report, détaillé dans project_members.
            $table->foreignId('chef_projet_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('back_up_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sponsor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('referent_technique_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['statut', 'type_projet']);
            $table->index('chef_projet_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
