<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estudios', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('paciente_id')->index();
            $table->uuid('atencion_id')->nullable()->index();
            $table->string('codigo', 40)->unique();
            $table->string('tipo', 30)->index(); // LABORATORIO, TOMOGRAFIA, ECOGRAFIA, ECOCARDIOGRAMA
            $table->string('nombre', 180);
            $table->string('estado', 30)->default('SOLICITADO')->index();
            $table->string('estado_detalle', 50)->nullable();
            $table->text('indicaciones')->nullable();
            $table->longText('informe')->nullable();
            $table->string('solicitado_por', 120)->nullable();
            $table->string('informado_por', 120)->nullable();
            $table->timestamp('solicitado_en')->useCurrent();
            $table->timestamp('realizado_en')->nullable();
            $table->timestamp('informado_en')->nullable();
            $table->timestamp('entregado_en')->nullable();
            $table->timestamp('cancelado_en')->nullable();
            $table->text('motivo_cancelacion')->nullable();
            $table->timestamps();
            $table->foreign('paciente_id')->references('id')->on('pacientes')->restrictOnDelete();
            $table->foreign('atencion_id')->references('id')->on('atenciones')->nullOnDelete();
            $table->index(['paciente_id', 'solicitado_en']);
        });
        // Cada fila pertenece a un único estudio; contenido permite formularios/versiones sin alterar el esquema base.
        foreach (['estudios_laboratorio', 'estudios_tomografia', 'estudios_ecografia', 'estudios_ecocardiograma'] as $tabla) {
            Schema::create($tabla, function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('estudio_id')->unique();
                $table->json('contenido')->nullable();
                $table->json('archivos')->nullable();
                $table->text('observaciones_tecnicas')->nullable();
                $table->timestamps();
                $table->foreign('estudio_id')->references('id')->on('estudios')->cascadeOnDelete();
            });
        }
        Schema::table('estudios_tomografia', function (Blueprint $table): void {
            $table->string('orthanc_study_id', 100)->nullable()->unique()->after('estudio_id');
            $table->string('dicom_study_uid', 150)->nullable()->index()->after('orthanc_study_id');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('estudios_ecocardiograma'); Schema::dropIfExists('estudios_ecografia');
        Schema::dropIfExists('estudios_tomografia'); Schema::dropIfExists('estudios_laboratorio'); Schema::dropIfExists('estudios');
    }
};
