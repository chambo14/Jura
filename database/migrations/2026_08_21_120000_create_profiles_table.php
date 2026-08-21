<?php

use App\Enums\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Profils d'accès. Les quatre profils MPM sont marqués « système » :
     * leurs droits restent modifiables, mais ils ne peuvent pas être supprimés.
     */
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('nom');
            $table->text('description')->nullable();
            $table->string('couleur')->default('zinc');
            $table->unsignedSmallInteger('ordre')->default(99);
            $table->boolean('systeme')->default(false);

            foreach (Permission::codes() as $permission) {
                $table->boolean($permission)->default(false);
            }

            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('profile_id')->nullable()->after('email')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('profile_id');
        });

        Schema::dropIfExists('profiles');
    }
};
