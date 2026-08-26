<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'équipe métier d'appartenance : c'est la maille de lecture du tableau
 * d'avancement, où le travail est suivi équipe par équipe et non projet
 * par projet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('equipe')->nullable()->after('poste');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('equipe');
        });
    }
};
