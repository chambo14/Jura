<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Les référents techniques confient leurs tâches à leur équipe : on trace qui
     * a délégué, pour que la charge se lise sur celui qui exécute sans perdre
     * la responsabilité de celui qui a confié.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('delegue_par_id')->nullable()->after('assignee_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delegue_par_id');
        });
    }
};
