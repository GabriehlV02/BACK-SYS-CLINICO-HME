<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('especialidades', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('codigo', 30)->unique();
            $table->string('nombre', 120)->unique();
            $table->text('descripcion')->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });

        Schema::create('medicos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->string('nombres', 100);
            $table->string('apellido_paterno', 100);
            $table->string('apellido_materno', 100)->nullable();
            $table->string('matricula_profesional', 60)->unique();
            $table->string('telefono', 30)->nullable();
            $table->string('email', 120)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('medico_especialidad', function (Blueprint $table): void {
            $table->uuid('medico_id');
            $table->uuid('especialidad_id');
            $table->boolean('principal')->default(false);
            $table->timestamps();

            $table->primary(['medico_id', 'especialidad_id']);
            $table->foreign('medico_id')->references('id')->on('medicos')->restrictOnDelete();
            $table->foreign('especialidad_id')->references('id')->on('especialidades')->restrictOnDelete();
        });

        Schema::create('consultorios', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('codigo', 30)->unique();
            $table->string('nombre', 100);
            $table->string('ubicacion', 150)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('consultas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('paciente_id')->index();
            $table->uuid('medico_id')->index();
            $table->uuid('especialidad_id')->index();
            $table->uuid('consultorio_id')->index();
            $table->timestamp('fecha_hora_programada')->index();
            $table->unsignedSmallInteger('duracion_minutos')->default(30);
            $table->string('estado', 20)->default('programada')->index();
            $table->text('motivo_consulta')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamp('confirmada_en')->nullable();
            $table->timestamp('atendida_en')->nullable();
            $table->timestamp('cancelada_en')->nullable();
            $table->string('motivo_cancelacion', 255)->nullable();
            $table->timestamps();

            $table->index(['medico_id', 'fecha_hora_programada']);
            $table->index(['consultorio_id', 'fecha_hora_programada']);
            $table->foreign('paciente_id')->references('id')->on('pacientes')->restrictOnDelete();
            $table->foreign('medico_id')->references('id')->on('medicos')->restrictOnDelete();
            $table->foreign('especialidad_id')->references('id')->on('especialidades')->restrictOnDelete();
            $table->foreign('consultorio_id')->references('id')->on('consultorios')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultas');
        Schema::dropIfExists('consultorios');
        Schema::dropIfExists('medico_especialidad');
        Schema::dropIfExists('medicos');
        Schema::dropIfExists('especialidades');
    }
};
