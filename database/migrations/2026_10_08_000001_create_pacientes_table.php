<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pacientes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('nombres', 100);
            $table->string('apellido_paterno', 100);
            $table->string('apellido_materno', 100);
            $table->string('ci', 25)->unique();
            $table->string('complemento_ci', 10)->nullable();
            $table->string('expedido_en', 50)->nullable();
            $table->string('nit', 25)->nullable();
            $table->string('razon_social', 180)->nullable();
            $table->date('fecha_nacimiento');
            $table->string('telefono', 30)->nullable();
            $table->string('genero', 30);
            $table->boolean('ci_con_qr')->default(false);
            $table->boolean('afroamericano')->default(false);
            $table->string('pais', 80)->default('Bolivia');
            $table->string('departamento', 80)->default('Cochabamba');
            $table->string('ciudad', 100)->nullable();
            $table->string('zona_barrio', 150)->nullable();
            $table->string('direccion_domicilio', 255)->nullable();
            $table->string('responsable_nombre_completo', 180)->nullable();
            $table->string('responsable_telefono', 30)->nullable();
            $table->string('responsable_parentesco', 80)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('pacientes'); }
};
