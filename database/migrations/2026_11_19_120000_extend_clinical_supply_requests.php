<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('usuarios')->table('clinico_solicitudes_insumos', function (Blueprint $table): void {
            $table->string('servicio', 120)->nullable();
            $table->json('pacientes')->nullable();
            $table->uuid('anulada_por')->nullable();
            $table->timestamp('anulada_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('usuarios')->table('clinico_solicitudes_insumos', function (Blueprint $table): void {
            $table->dropColumn(['servicio', 'pacientes', 'anulada_por', 'anulada_en']);
        });
    }
};
