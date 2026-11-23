<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('usuarios');
        if (! $schema->hasTable('clinico_solicitudes_insumos')) {
            throw new \RuntimeException('Ejecuta primero las migraciones contables en la base configurada para usuarios.');
        }

        $columnas = $schema->getColumnListing('clinico_solicitudes_insumos');
        $schema->table('clinico_solicitudes_insumos', function (Blueprint $table) use ($columnas): void {
            if (! in_array('servicio', $columnas, true)) {
                $table->string('servicio', 120)->nullable();
            }
            if (! in_array('pacientes', $columnas, true)) {
                $table->json('pacientes')->nullable();
            }
            if (! in_array('anulada_por', $columnas, true)) {
                $table->uuid('anulada_por')->nullable();
            }
            if (! in_array('anulada_en', $columnas, true)) {
                $table->timestamp('anulada_en')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::connection('usuarios')->table('clinico_solicitudes_insumos', function (Blueprint $table): void {
            $table->dropColumn(['servicio', 'pacientes', 'anulada_por', 'anulada_en']);
        });
    }
};
