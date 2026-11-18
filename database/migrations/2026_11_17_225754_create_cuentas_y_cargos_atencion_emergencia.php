<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cuentas_atencion_emergencia', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('emergencia_id')->unique()->constrained('emergencias')->restrictOnDelete();
            $table->string('estado', 20)->default('abierta');
            $table->string('moneda', 3)->default('BOB');
            $table->timestamps();
        });
        Schema::create('cargos_atencion_emergencia', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('cuenta_id')->constrained('cuentas_atencion_emergencia')->restrictOnDelete();
            $table->string('registro_id', 100);
            $table->string('item_id', 100);
            $table->json('datos');
            $table->boolean('vigente')->default(true);
            $table->timestamps();
            $table->unique(['cuenta_id', 'registro_id']);
        });

        DB::table('emergencias')->orderBy('id')->chunk(100, function ($emergencias): void {
            foreach ($emergencias as $emergencia) {
                $cuentaId = (string) Str::uuid();
                DB::table('cuentas_atencion_emergencia')->insert(['id' => $cuentaId, 'emergencia_id' => $emergencia->id, 'estado' => 'abierta', 'moneda' => 'BOB', 'created_at' => now(), 'updated_at' => now()]);
                $ingreso = json_decode($emergencia->datos_ingreso ?? '{}', true) ?: [];
                foreach ($ingreso['consumos'] ?? [] as $consumo) {
                    $itemId = $consumo['itemId'] ?? $consumo['id'] ?? null;
                    if (empty($consumo['registroId']) || empty($itemId)) continue;
                    DB::table('cargos_atencion_emergencia')->updateOrInsert(
                        ['cuenta_id' => $cuentaId, 'registro_id' => $consumo['registroId']],
                        ['id' => (string) Str::uuid(), 'item_id' => $itemId, 'datos' => json_encode($consumo), 'created_at' => now(), 'updated_at' => now()],
                    );
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cargos_atencion_emergencia');
        Schema::dropIfExists('cuentas_atencion_emergencia');
    }
};
