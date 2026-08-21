<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->string('sigle')->nullable();
            $table->string('pays')->nullable();
            $table->string('secteur')->nullable();
            $table->string('contact_nom')->nullable();
            $table->string('contact_email')->nullable();
            $table->timestamps();

            $table->unique('nom');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
