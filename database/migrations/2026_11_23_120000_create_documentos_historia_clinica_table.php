<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos_historia_clinica', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('paciente_id')->index();
            $table->uuid('emergencia_id')->index();
            $table->string('tipo', 50)->default('reporte_emergencias');
            $table->string('titulo', 180);
            $table->string('nombre_archivo', 255);
            $table->string('ruta', 500);
            $table->string('mime', 100)->default('application/pdf');
            $table->string('periodo_desde', 40)->nullable();
            $table->string('periodo_hasta', 40)->nullable();
            $table->uuid('creado_por')->nullable();
            $table->timestamps();
            $table->foreign('emergencia_id')->references('id')->on('emergencias')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos_historia_clinica');
    }
};
