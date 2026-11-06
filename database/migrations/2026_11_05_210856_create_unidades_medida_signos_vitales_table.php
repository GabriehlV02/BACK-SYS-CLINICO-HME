<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('unidades_medida_signos_vitales', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 120);
            $table->string('clave', 120)->unique();
            $table->string('abreviatura', 32);
            $table->timestamps();
        });

        foreach (DB::table('configuracion_signos_vitales')->select('unidad', 'abreviatura')->get() as $signo) {
            $nombre = Str::squish($signo->unidad);
            $clave = Str::lower(Str::ascii($nombre));
            if ($nombre !== '' && trim($signo->abreviatura) !== '') {
                DB::table('unidades_medida_signos_vitales')->insertOrIgnore([
                    'nombre' => $nombre, 'clave' => $clave, 'abreviatura' => trim($signo->abreviatura),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unidades_medida_signos_vitales');
    }
};
