<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('citas', function (Blueprint $table): void {
            $table->id();
            $table->uuid('paciente_id')->index();
            $table->foreign('paciente_id')->references('id')->on('pacientes')->restrictOnDelete();
            $table->string('doctor', 180);
            $table->string('especialidad', 120);
            $table->string('consultorio', 100);
            $table->date('fecha')->index();
            $table->string('hora', 5);
            $table->string('estado', 20)->default('por_confirmar');
            $table->string('reserva_horario', 180)->nullable()->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('citas');
    }
};
