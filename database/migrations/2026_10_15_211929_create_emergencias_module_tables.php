<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('espacios_atencion', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('codigo', 40)->unique();
            $table->string('nombre', 100);
            $table->string('tipo', 30);
            $table->string('ubicacion', 120)->nullable();
            $table->string('estado', 20)->default('libre');
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('historias_clinicas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('paciente_id')->unique();
            $table->string('numero_historia', 40)->unique();
            $table->timestamp('abierta_en')->useCurrent();
            $table->timestamps();
            $table->foreign('paciente_id')->references('id')->on('pacientes')->restrictOnDelete();
        });

        Schema::create('atenciones', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('paciente_id')->nullable()->index();
            $table->uuid('historia_clinica_id')->nullable()->index();
            $table->uuid('espacio_atencion_id')->nullable()->index();
            $table->string('tipo', 30)->default('emergencia');
            $table->unsignedInteger('correlativo_paciente')->nullable();
            $table->string('codigo_atencion', 60)->nullable()->unique();
            $table->string('estado', 20)->default('en_curso');
            $table->text('motivo')->nullable();
            $table->timestamp('iniciada_en')->useCurrent();
            $table->timestamp('finalizada_en')->nullable();
            $table->timestamps();
            $table->unique(['paciente_id', 'correlativo_paciente']);
            $table->foreign('paciente_id')->references('id')->on('pacientes')->restrictOnDelete();
            $table->foreign('historia_clinica_id')->references('id')->on('historias_clinicas')->restrictOnDelete();
            $table->foreign('espacio_atencion_id')->references('id')->on('espacios_atencion')->restrictOnDelete();
        });

        Schema::create('emergencias', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('atencion_id')->unique();
            $table->string('codigo_emergencia', 40)->unique();
            $table->string('nivel_triaje', 30)->nullable();
            $table->text('motivo_consulta')->nullable();
            $table->json('identidad_provisional')->nullable();
            $table->json('datos_ingreso')->nullable();
            $table->timestamps();
            $table->foreign('atencion_id')->references('id')->on('atenciones')->restrictOnDelete();
        });

        Schema::create('cuentas_emergencia', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('emergencia_id')->unique();
            $table->string('codigo_cuenta', 40)->unique();
            $table->string('estado', 20)->default('abierta');
            $table->char('moneda', 3)->default('BOB');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('descuento', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('monto_pagado', 12, 2)->default(0);
            $table->decimal('saldo_pendiente', 12, 2)->default(0);
            $table->timestamp('cerrada_en')->nullable();
            $table->timestamps();
            $table->foreign('emergencia_id')->references('id')->on('emergencias')->restrictOnDelete();
        });

        Schema::create('consumos_emergencia', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('cuenta_emergencia_id')->index();
            $table->uuid('catalogo_item_id')->nullable()->index();
            $table->string('codigo_item', 60)->nullable();
            $table->string('nombre_item', 180);
            $table->string('tipo_item', 30);
            $table->string('unidad_medida', 30)->nullable();
            $table->decimal('cantidad', 12, 3)->default(1);
            $table->decimal('precio_unitario', 12, 2);
            $table->boolean('por_hora')->default(false);
            $table->string('estado', 20)->default('registrado');
            $table->timestamp('iniciado_en')->nullable();
            $table->timestamp('finalizado_en')->nullable();
            $table->text('observacion')->nullable();
            $table->timestamps();
            $table->foreign('cuenta_emergencia_id')->references('id')->on('cuentas_emergencia')->restrictOnDelete();
        });

        Schema::create('pagos_emergencia', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('cuenta_emergencia_id')->index();
            $table->string('metodo', 40);
            $table->decimal('monto', 12, 2);
            $table->string('referencia', 120)->nullable();
            $table->timestamp('pagado_en')->useCurrent();
            $table->timestamps();
            $table->foreign('cuenta_emergencia_id')->references('id')->on('cuentas_emergencia')->restrictOnDelete();
        });

        Schema::create('signos_vitales_emergencia', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('emergencia_id')->index();
            $table->json('valores');
            $table->text('observacion')->nullable();
            $table->string('registrado_por', 120)->nullable();
            $table->timestamp('registrado_en')->useCurrent();
            $table->timestamps();
            $table->foreign('emergencia_id')->references('id')->on('emergencias')->restrictOnDelete();
        });

        Schema::create('evaluaciones_emergencia', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('emergencia_id')->index();
            $table->unsignedInteger('revision')->default(1);
            $table->json('campos');
            $table->string('profesional', 120)->nullable();
            $table->timestamp('registrado_en')->useCurrent();
            $table->timestamps();
            $table->unique(['emergencia_id', 'revision']);
            $table->foreign('emergencia_id')->references('id')->on('emergencias')->restrictOnDelete();
        });

        Schema::create('ordenes_emergencia', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('emergencia_id')->index();
            $table->uuid('catalogo_item_id')->nullable()->index();
            $table->string('tipo', 30);
            $table->string('titulo', 180);
            $table->text('indicaciones')->nullable();
            $table->string('estado', 20)->default('pendiente');
            $table->unsignedInteger('revision')->default(1);
            $table->json('detalles')->nullable();
            $table->string('profesional', 120)->nullable();
            $table->timestamp('emitida_en')->useCurrent();
            $table->timestamps();
            $table->foreign('emergencia_id')->references('id')->on('emergencias')->restrictOnDelete();
        });

        Schema::create('aplicaciones_ordenes_emergencia', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('orden_emergencia_id')->index();
            $table->uuid('consumo_emergencia_id')->nullable()->index();
            $table->string('solicitud_id', 64)->unique();
            $table->decimal('cantidad', 12, 3)->nullable();
            $table->text('observacion')->nullable();
            $table->string('registrado_por', 120)->nullable();
            $table->timestamp('aplicada_en')->useCurrent();
            $table->timestamps();
            $table->foreign('orden_emergencia_id')->references('id')->on('ordenes_emergencia')->restrictOnDelete();
            $table->foreign('consumo_emergencia_id')->references('id')->on('consumos_emergencia')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aplicaciones_ordenes_emergencia');
        Schema::dropIfExists('ordenes_emergencia');
        Schema::dropIfExists('evaluaciones_emergencia');
        Schema::dropIfExists('signos_vitales_emergencia');
        Schema::dropIfExists('pagos_emergencia');
        Schema::dropIfExists('consumos_emergencia');
        Schema::dropIfExists('cuentas_emergencia');
        Schema::dropIfExists('emergencias');
        Schema::dropIfExists('atenciones');
        Schema::dropIfExists('historias_clinicas');
        Schema::dropIfExists('espacios_atencion');
    }
};
