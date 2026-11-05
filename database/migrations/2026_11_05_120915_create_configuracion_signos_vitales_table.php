<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracion_signos_vitales', function (Blueprint $table): void {
            $table->string('id', 80)->primary();
            $table->string('nombre', 120);
            $table->string('unidad', 120);
            $table->string('abreviatura', 32);
            $table->string('tipo', 30);
            $table->json('rango_referencia')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
        $signos = [
            ['temperatura', 'Temperatura', 'Grados Celsius', '°C', 'Número decimal'],
            ['glicemia', 'Glicemia capilar', 'Miligramos por decilitro', 'mg/dL', 'Número entero'],
            ['frecuenciaCardiaca', 'Frecuencia cardíaca', 'Pulsaciones por minuto', 'P/min', 'Número entero'],
            ['frecuenciaRespiratoria', 'Frecuencia respiratoria', 'Respiraciones por minuto', 'Res/min', 'Número entero'],
            ['presionSistolica', 'Presión sistólica', 'Milímetros de mercurio', 'mmHg', 'Número entero'],
            ['presionDiastolica', 'Presión diastólica', 'Milímetros de mercurio', 'mmHg', 'Número entero'],
            ['saturacionAmbiente', 'Saturación ambiente', 'Porcentaje', '%', 'Número entero'],
            ['oxigeno', 'Oxígeno', 'Litros', 'L', 'Número decimal'],
            ['saturacionOxigeno', 'Saturación con oxígeno', 'Porcentaje', '%', 'Número entero'],
            ['perimetroCintura', 'Perímetro de cintura', 'Centímetros', 'cm', 'Número decimal'],
            ['perimetroCadera', 'Perímetro de cadera', 'Centímetros', 'cm', 'Número decimal'],
            ['peso', 'Peso', 'Kilogramos', 'kg', 'Número decimal'],
            ['estatura', 'Estatura', 'Centímetros', 'cm', 'Número decimal'],
            ['imc', 'IMC', 'Índice de masa corporal', 'kg/m²', 'Número decimal'],
        ];
        foreach ($signos as [$id, $nombre, $unidad, $abreviatura, $tipo]) {
            DB::table('configuracion_signos_vitales')->insert(compact('id', 'nombre', 'unidad', 'abreviatura', 'tipo') + ['activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracion_signos_vitales');
    }
};
