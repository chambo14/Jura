<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ressources affectées au projet, avec taux d'allocation et fenêtre d'affectation.
     */
    public function up(): void
    {
        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->unsignedTinyInteger('allocation_pct')->default(100);
            $table->date('date_debut')->nullable();
            $table->date('date_fin')->nullable();
            $table->text('commentaire')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'user_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_members');
    }
};
