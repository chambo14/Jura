<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lignes des trois rubriques du flash report : activités réalisées,
     * activités à réaliser, points d'attention & alertes.
     */
    public function up(): void
    {
        Schema::create('flash_report_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flash_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->text('libelle');
            $table->date('date_ref')->nullable();
            $table->string('statut_libelle')->nullable();
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestamps();

            $table->index(['flash_report_id', 'type', 'ordre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flash_report_items');
    }
};
