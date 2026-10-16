<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios_sistema', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tipo_usuario', 15);
            $table->uuid('paciente_id')->nullable()->index();
            $table->string('nombres', 100);
            $table->string('apellidos', 100)->nullable();
            $table->string('ci', 25)->nullable();
            $table->string('correo', 150)->nullable();
            $table->string('usuario', 60)->unique();
            $table->string('password');
            $table->string('rol', 60)->nullable();
            $table->string('estado', 15)->default('ACTIVO');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios_sistema');
    }
};
