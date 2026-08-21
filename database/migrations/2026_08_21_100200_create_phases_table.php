<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Référentiel des 8 phases de la Méthodologie Projet MEDIASOFT (MPM).
     */
    public function up(): void
    {
        Schema::create('phases', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('nom');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('ordre');
            $table->string('couleur')->default('zinc');
            $table->timestamps();

            $table->index('ordre');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phases');
    }
};
