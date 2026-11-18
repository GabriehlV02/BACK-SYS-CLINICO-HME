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
        Schema::create('emergencia_balance_hidrico', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('emergencia_id')->constrained('emergencias')->restrictOnDelete();
            $table->string('tipo', 10);
            $table->unsignedInteger('volumen');
            $table->string('detalle', 180);
            $table->text('anotacion')->nullable();
            $table->date('fecha');
            $table->string('hora', 5);
            $table->uuid('autor_id')->nullable();
            $table->timestamps();
            $table->index(['emergencia_id', 'fecha', 'hora']);
        });
        Schema::create('emergencia_traslados_ambulancia', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('emergencia_id')->unique()->constrained('emergencias')->restrictOnDelete();
            $table->date('fecha');
            $table->string('hora', 5);
            $table->string('destino', 200);
            $table->text('motivo');
            $table->uuid('autor_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emergencia_traslados_ambulancia');
        Schema::dropIfExists('emergencia_balance_hidrico');
    }
};
