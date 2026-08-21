<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Référentiel des étapes du bandeau "PHASE ACTUELLE DU PROJET" du flash report.
     * Le libellé et la séquence dépendent du type de projet.
     */
    public function up(): void
    {
        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->string('type_projet');
            $table->foreignId('phase_id')->nullable()->constrained()->nullOnDelete();
            $table->string('libelle');
            $table->unsignedSmallInteger('ordre');
            $table->timestamps();

            $table->unique(['type_projet', 'ordre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_steps');
    }
};
