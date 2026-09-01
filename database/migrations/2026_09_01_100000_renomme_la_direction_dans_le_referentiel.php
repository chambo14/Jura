<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrige le nom de la maison dans le référentiel des livrables.
 *
 * Trois livrables de la phase Opportunité — note d'opportunité, business case,
 * charte de projet — désignaient « Direction MEDIALOGIC » comme responsable.
 * C'est MEDIASOFT LAFAYETTE. Le seeder porte désormais le bon libellé, mais il
 * ne rejoue pas sur une base déjà en service : la correction doit passer ici.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('deliverable_types')
            ->where('responsable', 'Direction MEDIALOGIC')
            ->update(['responsable' => 'Direction MEDIASOFT LAFAYETTE']);
    }

    public function down(): void
    {
        DB::table('deliverable_types')
            ->where('responsable', 'Direction MEDIASOFT LAFAYETTE')
            ->update(['responsable' => 'Direction MEDIALOGIC']);
    }
};
