<?php

use App\Enums\MemberRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sépare la fonction d'un collaborateur de son profil d'accès.
     *
     * Le profil dit ce qu'un compte a le droit de faire dans l'application ;
     * il ne dit rien de son métier. Les quatre profils livrés rangeaient donc
     * référents techniques, testeurs, développeurs et intégrateurs sous le même
     * « membre d'équipe », et les listes du bloc « Pilotage » proposaient un
     * testeur comme référent technique.
     *
     * La fonction est reprise une fois de l'intitulé de poste, qui la portait
     * en toutes lettres. Ensuite, c'est elle qui fait foi.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('fonction')->nullable()->after('poste');
        });

        foreach (DB::table('users')->select('id', 'poste')->get() as $utilisateur) {
            $fonction = MemberRole::depuisUnPoste($utilisateur->poste);

            if ($fonction !== null) {
                DB::table('users')->where('id', $utilisateur->id)->update(['fonction' => $fonction->value]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('fonction');
        });
    }
};
