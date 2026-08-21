<?php

use App\Enums\ProjectCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rubrique du sommaire du comité projets.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('categorie')->default(ProjectCategory::Autres->value)->after('type_projet');
            $table->unsignedSmallInteger('ordre_comite')->default(0)->after('categorie');

            $table->index(['categorie', 'ordre_comite']);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['categorie', 'ordre_comite']);
            $table->dropColumn(['categorie', 'ordre_comite']);
        });
    }
};
