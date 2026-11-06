<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $fuente = 'https://medlineplus.gov/ency/article/002341.htm';
        $contexto = 'Referencia orientativa para adultos sanos en reposo, pendiente de validación clínica institucional. No aplicar automáticamente a niños, embarazo u otras condiciones.';
        $rangos = [
            ['temperatura', 'Temperatura', 'Grados Celsius', 'Temperatura en adultos', 36.5, 37.3, $fuente, $contexto],
            ['frecuenciaCardiaca', 'Frecuencia cardíaca', 'Pulsaciones por minuto', 'Frecuencia cardíaca en adultos', 60, 100, $fuente, $contexto],
            ['frecuenciaRespiratoria', 'Frecuencia respiratoria', 'Respiraciones por minuto', 'Frecuencia respiratoria en adultos', 12, 18, $fuente, $contexto],
            ['presionSistolica', 'Presión sistólica', 'Milímetros de mercurio', 'Presión sistólica en adultos', 90, 120, $fuente, $contexto],
            ['presionDiastolica', 'Presión diastólica', 'Milímetros de mercurio', 'Presión diastólica en adultos', 60, 80, $fuente, $contexto],
            ['imc', 'IMC', 'Índice de masa corporal', 'IMC: peso saludable en adultos', 18.5, 24.9, 'https://www.cdc.gov/bmi/adult-calculator/index.html', 'Referencia orientativa para adultos de 20 años o más, pendiente de validación clínica institucional. No aplicar en pediatría ni durante el embarazo; el IMC es una medida de tamizaje.'],
        ];

        foreach ($rangos as [$id, $nombre, $unidad, $nombreRango, $minimo, $maximo, $fuenteRango, $contextoRango]) {
            DB::table('configuracion_signos_vitales')
                ->where('id', $id)->where('nombre', $nombre)->where('unidad', $unidad)
                ->where('activo', true)->whereNull('rango_referencia')->whereNull('componentes')
                ->update(['rango_referencia' => json_encode([
                    'nombre' => $nombreRango, 'minimo' => $minimo, 'maximo' => $maximo,
                    'fuente' => $fuenteRango, 'contexto' => $contextoRango,
                ], JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No se eliminan referencias que podrían haber sido modificadas después de la migración.
    }
};
