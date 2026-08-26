<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pièces jointes. Le rattachement est polymorphe — un fichier se dépose
 * sur un projet, un livrable ou une tâche — mais `project_id` est conservé
 * à part : c'est lui qui porte le contrôle d'accès et les listes projet,
 * sans avoir à remonter la chaîne des relations à chaque lecture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->morphs('attachable');
            $table->foreignId('depose_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nom');
            $table->string('chemin');
            $table->string('disque')->default('local');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('taille')->default(0);
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
