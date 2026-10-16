<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estancias_atencion', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('atencion_id')->index();
            // La categoría es estable y el espacio identifica el lugar físico (cama, box, consultorio, etc.).
            $table->string('area', 30)->index(); // EMERGENCIA, CONSULTORIO, INTERNACION, QUIROFANO, RECUPERACION, TERAPIA_INTENSIVA
            $table->uuid('espacio_atencion_id')->nullable()->index();
            $table->string('estado', 20)->default('ACTIVA')->index(); // ACTIVA, FINALIZADA, CANCELADA
            $table->string('motivo_ingreso', 255)->nullable();
            $table->string('motivo_salida', 255)->nullable();
            $table->json('datos_area')->nullable();
            $table->timestamp('ingresada_en')->useCurrent();
            $table->timestamp('egresada_en')->nullable();
            $table->timestamps();

            $table->index(['atencion_id', 'ingresada_en']);
            $table->foreign('atencion_id')->references('id')->on('atenciones')->restrictOnDelete();
            $table->foreign('espacio_atencion_id')->references('id')->on('espacios_atencion')->restrictOnDelete();
        });

        Schema::create('estancia_profesionales', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('estancia_atencion_id')->index();
            $table->uuid('usuario_id')->nullable()->index();
            $table->uuid('medico_id')->nullable()->index();
            $table->string('rol', 40); // RESPONSABLE, MEDICO_TRATANTE, ENFERMERIA, ANESTESIA, TECNICO, APOYO
            $table->timestamp('asignado_en')->useCurrent();
            $table->timestamp('retirado_en')->nullable();
            $table->timestamps();

            $table->foreign('estancia_atencion_id')->references('id')->on('estancias_atencion')->cascadeOnDelete();
            $table->foreign('usuario_id')->references('id')->on('usuarios_sistema')->nullOnDelete();
            $table->foreign('medico_id')->references('id')->on('medicos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estancia_profesionales');
        Schema::dropIfExists('estancias_atencion');
    }
};
