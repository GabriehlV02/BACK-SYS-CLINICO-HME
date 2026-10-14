<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paciente_responsables', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('paciente_id')->index();
            $table->string('nombre_completo', 180);
            $table->string('telefono', 30)->nullable();
            $table->string('parentesco', 80)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->foreign('paciente_id')->references('id')->on('pacientes')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paciente_responsables');
    }
};
