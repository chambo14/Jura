<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Journal des changements de valeurs critiques.
     *
     * L'état courant était écrasé à chaque mise à jour : un chiffre présenté
     * au comité ne pouvait ni être expliqué, ni être reconstitué. Chaque
     * variation d'un attribut suivi laisse désormais une trace : qui, quand,
     * de quoi vers quoi, et à quelle occasion.
     */
    public function up(): void
    {
        Schema::create('audit_entries', function (Blueprint $table) {
            $table->id();
            $table->morphs('auditable');

            // Le projet auquel rattacher la ligne, même quand l'objet modifié
            // est une carte ou un livrable : l'historique se lit par projet.
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();

            // L'auteur peut manquer : une commande planifiée n'a pas de compte.
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('attribut');
            $table->text('ancienne_valeur')->nullable();
            $table->text('nouvelle_valeur')->nullable();
            $table->string('motif')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['project_id', 'created_at']);
            $table->index(['auditable_type', 'auditable_id', 'attribut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_entries');
    }
};
