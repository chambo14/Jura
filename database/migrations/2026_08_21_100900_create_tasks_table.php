<?php

use App\Enums\ProgressStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tâches hebdomadaires : elles alimentent les rubriques "activités réalisées"
     * et "activités à réaliser" du flash report.
     */
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_phase_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('libelle');
            $table->text('description')->nullable();
            $table->date('date_debut')->nullable();
            $table->date('date_echeance')->nullable();
            $table->date('date_realisation')->nullable();
            $table->decimal('avancement_pct', 5, 2)->default(0);
            $table->unsignedSmallInteger('charge_jours')->nullable();
            $table->string('statut')->default(ProgressStatus::NonDemarre->value);
            $table->timestamps();

            $table->index(['project_id', 'statut']);
            $table->index('date_echeance');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
