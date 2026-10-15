<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmergenciasEsquemaTest extends TestCase
{
    use DatabaseMigrations;

    public function test_puede_registrar_una_emergencia_anonima_con_cuenta_y_consumo(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        foreach ([
            'espacios_atencion', 'historias_clinicas', 'atenciones', 'emergencias',
            'cuentas_emergencia', 'consumos_emergencia', 'pagos_emergencia',
            'signos_vitales_emergencia', 'evaluaciones_emergencia', 'ordenes_emergencia',
            'aplicaciones_ordenes_emergencia',
        ] as $tabla) {
            $this->assertTrue(Schema::hasTable($tabla), "Falta la tabla {$tabla}.");
        }

        $ahora = now();
        $espacioId = (string) Str::uuid();
        $atencionId = (string) Str::uuid();
        $emergenciaId = (string) Str::uuid();
        $cuentaId = (string) Str::uuid();
        $consumoId = (string) Str::uuid();

        DB::table('espacios_atencion')->insert([
            'id' => $espacioId, 'codigo' => 'URG-01', 'nombre' => 'Cubículo 1',
            'tipo' => 'cubiculo', 'estado' => 'ocupado', 'activo' => true,
            'created_at' => $ahora, 'updated_at' => $ahora,
        ]);
        DB::table('atenciones')->insert([
            'id' => $atencionId, 'espacio_atencion_id' => $espacioId,
            'tipo' => 'emergencia', 'estado' => 'en_curso', 'iniciada_en' => $ahora,
            'created_at' => $ahora, 'updated_at' => $ahora,
        ]);
        DB::table('emergencias')->insert([
            'id' => $emergenciaId, 'atencion_id' => $atencionId,
            'codigo_emergencia' => 'EM-2026-000001', 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);
        DB::table('cuentas_emergencia')->insert([
            'id' => $cuentaId, 'emergencia_id' => $emergenciaId,
            'codigo_cuenta' => 'CE-2026-000001', 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);
        DB::table('consumos_emergencia')->insert([
            'id' => $consumoId, 'cuenta_emergencia_id' => $cuentaId,
            'nombre_item' => 'Material de curación', 'tipo_item' => 'producto',
            'cantidad' => 1, 'precio_unitario' => 12.50,
            'created_at' => $ahora, 'updated_at' => $ahora,
        ]);

        $this->assertDatabaseHas('atenciones', ['id' => $atencionId, 'paciente_id' => null]);
        $this->assertDatabaseHas('emergencias', ['id' => $emergenciaId, 'atencion_id' => $atencionId]);
        $this->assertDatabaseHas('consumos_emergencia', ['id' => $consumoId, 'cuenta_emergencia_id' => $cuentaId]);
    }
}
