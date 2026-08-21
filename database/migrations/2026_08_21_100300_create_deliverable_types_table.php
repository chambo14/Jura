<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Référentiel des livrables types MPM, rattachés à une phase et à une entité responsable.
     */
    public function up(): void
    {
        Schema::create('deliverable_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('phase_id')->constrained()->cascadeOnDelete();
            $table->string('nom');
            $table->string('responsable')->nullable();
            $table->text('description')->nullable();
            $table->boolean('obligatoire')->default(true);
            $table->json('types_projet')->nullable();
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestamps();

            $table->unique(['phase_id', 'nom']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliverable_types');
    }
};
