<?php

use App\Enums\ProgressStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Instanciation des 8 phases MPM sur un projet donné : c'est la base du suivi des délais.
     */
    public function up(): void
    {
        Schema::create('project_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('phase_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('ordre');
            $table->date('date_debut_prevue')->nullable();
            $table->date('date_fin_prevue')->nullable();
            $table->date('date_debut_reelle')->nullable();
            $table->date('date_fin_reelle')->nullable();
            $table->decimal('avancement_pct', 5, 2)->default(0);
            $table->string('statut')->default(ProgressStatus::NonDemarre->value);
            $table->text('commentaire')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'phase_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_phases');
    }
};
