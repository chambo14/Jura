<?php

use App\Enums\TaskPriority;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ce qu'il manquait aux tâches pour tenir un tableau de type kanban :
 * une priorité, et un rang à l'intérieur de la colonne — c'est lui qui
 * conserve l'ordre choisi en faisant glisser les cartes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('priorite')->default(TaskPriority::Normale->value)->after('statut');
            $table->unsignedInteger('ordre')->default(0)->after('priorite');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->index(['statut', 'ordre']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['statut', 'ordre']);
            $table->dropColumn(['priorite', 'ordre']);
        });
    }
};
