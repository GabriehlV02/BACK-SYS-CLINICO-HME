<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('atenciones_consulta', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cita_id')->unique()->constrained('citas')->restrictOnDelete();
            $table->uuid('registrado_por');
            $table->json('contenido');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('finalizada_en')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('atenciones_consulta'); }
};
