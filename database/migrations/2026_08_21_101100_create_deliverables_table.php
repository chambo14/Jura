<?php

use App\Enums\DeliverableStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Livrables réellement attendus sur un projet. Instanciés depuis le référentiel
     * MPM à la création du projet, puis ajustés par le chef de projet.
     */
    public function up(): void
    {
        Schema::create('deliverables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('phase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deliverable_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('nom');
            $table->string('statut')->default(DeliverableStatus::AProduire->value);
            $table->boolean('obligatoire')->default(true);
            $table->date('date_prevue')->nullable();
            $table->date('date_livraison')->nullable();
            $table->string('version')->nullable();
            $table->string('lien')->nullable();
            $table->string('fichier_path')->nullable();
            $table->text('commentaire')->nullable();
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestamps();

            $table->index(['project_id', 'phase_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliverables');
    }
};
