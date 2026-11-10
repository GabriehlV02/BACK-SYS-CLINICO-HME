<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('instrumental_quirurgico', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('codigo', 80)->unique();
            $table->string('nombre', 180);
            $table->string('lote', 100)->nullable();
            $table->unsignedInteger('cantidad');
            $table->text('descripcion')->nullable();
            $table->string('ubicacion', 150)->default('Almacén esterilización');
            $table->string('estado', 30)->default('pendiente');
            $table->string('imagen_ruta')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('instrumental_quirurgico'); }
};
