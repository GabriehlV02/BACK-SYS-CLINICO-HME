<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('valoraciones_signos_vitales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cita_id')->constrained('citas')->restrictOnDelete();
            $table->uuid('registrado_por');
            $table->json('mediciones');
            $table->timestamp('created_at');
            $table->index(['cita_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('valoraciones_signos_vitales');
    }
};
