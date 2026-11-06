<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servicios_atencion_signos', function (Blueprint $table): void {
            $table->string('id', 60)->primary();
            $table->string('nombre', 120);
            $table->string('descripcion', 255);
        });
        Schema::create('servicio_signo_vital', function (Blueprint $table): void {
            $table->string('servicio_id', 60);
            $table->string('signo_id', 80);
            $table->primary(['servicio_id', 'signo_id']);
            $table->foreign('servicio_id')->references('id')->on('servicios_atencion_signos')->cascadeOnDelete();
            $table->foreign('signo_id')->references('id')->on('configuracion_signos_vitales')->cascadeOnDelete();
        });
        $servicios = [
            ['id' => 'consulta-ambulatoria', 'nombre' => 'Servicio de Consulta Ambulatoria', 'descripcion' => 'Signos para consulta externa y agenda médica.'],
            ['id' => 'emergencias', 'nombre' => 'Servicio de Emergencias', 'descripcion' => 'Signos para triaje y atención de urgencias.'],
            ['id' => 'internacion', 'nombre' => 'Servicio de Internación', 'descripcion' => 'Monitoreo de pacientes hospitalizados.'],
            ['id' => 'terapia-intensiva', 'nombre' => 'Servicio de Terapia Intensiva', 'descripcion' => 'Vigilancia clínica de alta frecuencia.'],
            ['id' => 'quirofano', 'nombre' => 'Servicio de Quirófano', 'descripcion' => 'Control pre, intra y postoperatorio.'],
        ];
        DB::table('servicios_atencion_signos')->insert($servicios);
        $signos = DB::table('configuracion_signos_vitales')->where('activo', true)->pluck('id');
        foreach ($servicios as $servicio) {
            foreach ($signos as $signo) {
                DB::table('servicio_signo_vital')->insert(['servicio_id' => $servicio['id'], 'signo_id' => $signo]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('servicio_signo_vital');
        Schema::dropIfExists('servicios_atencion_signos');
    }
};
