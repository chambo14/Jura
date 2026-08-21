<?php

use App\Enums\ProgressStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_phase_id')->nullable()->constrained()->nullOnDelete();
            $table->string('libelle');
            $table->text('description')->nullable();
            $table->date('date_prevue');
            $table->date('date_prevue_initiale')->nullable();
            $table->date('date_reelle')->nullable();
            $table->string('statut')->default(ProgressStatus::NonDemarre->value);
            $table->boolean('critique')->default(false);
            $table->string('instance')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'date_prevue']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milestones');
    }
};
