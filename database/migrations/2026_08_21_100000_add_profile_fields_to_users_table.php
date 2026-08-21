<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default(UserRole::Membre->value)->after('email');
            $table->string('poste')->nullable()->after('role');
            $table->string('telephone')->nullable()->after('poste');
            $table->boolean('actif')->default(true)->after('telephone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'poste', 'telephone', 'actif']);
        });
    }
};
