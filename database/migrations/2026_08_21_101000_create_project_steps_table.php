<?php

use App\Enums\ProgressStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * État par projet de chaque étape du bandeau "PHASE ACTUELLE DU PROJET".
     * L'annotation reprend les mentions du type "UAT 90 %" vues sur les slides.
     */
    public function up(): void
    {
        Schema::create('project_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_step_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('ordre');
            $table->string('statut')->default(ProgressStatus::NonDemarre->value);
            $table->string('annotation')->nullable();
            $table->date('date_reelle')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'workflow_step_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_steps');
    }
};
