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
        Schema::create('auditorias_usuarios', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('usuario_id');
            $table->string('categoria', 30);
            $table->string('accion', 180);
            $table->string('metodo', 10);
            $table->string('ruta', 255);
            $table->string('ip', 45)->nullable();
            $table->timestamps();
            $table->index(['usuario_id', 'created_at']);
            $table->index(['usuario_id', 'categoria', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auditorias_usuarios');
    }
};
