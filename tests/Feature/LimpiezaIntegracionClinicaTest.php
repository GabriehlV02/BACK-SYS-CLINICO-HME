<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class LimpiezaIntegracionClinicaTest extends TestCase
{
    use RefreshDatabase;

    public function test_conserva_solo_las_tablas_del_backend_operativo(): void
    {
        foreach (['pacientes', 'paciente_responsables', 'registros_admision_paciente', 'atenciones', 'emergencias', 'espacios_atencion', 'estancias_atencion', 'estancia_profesionales', 'estudios', 'estudios_ecografia', 'estudios_ecocardiograma', 'estudios_laboratorio', 'estudios_tomografia', 'cajas', 'caja_almacen', 'migrations'] as $tabla) {
            $this->assertTrue(Schema::hasTable($tabla), $tabla);
        }
        foreach (['usuarios_sistema', 'roles', 'permisos', 'rol_usuario', 'permiso_rol', 'rol_sistema', 'sistemas', 'tipos_usuarios', 'users', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'consultas', 'medicos', 'especialidades', 'medico_especialidad', 'consultorios', 'historias_clinicas', 'almacenes', 'almacen_existencias', 'farmacia_productos', 'farmacia_movimientos', 'farmacia_almacen_items', 'cuentas_emergencia', 'consumos_emergencia', 'pagos_emergencia', 'ordenes_emergencia', 'aplicaciones_ordenes_emergencia', 'evaluaciones_emergencia', 'signos_vitales_emergencia'] as $tabla) {
            $this->assertFalse(Schema::hasTable($tabla), $tabla);
        }
        $this->assertFalse(Schema::hasColumn('estancia_profesionales', 'medico_id'));
        $this->assertFalse(Schema::hasColumn('atenciones', 'historia_clinica_id'));
    }

    public function test_no_elimina_datos_clinicos_si_una_estructura_retirada_tiene_registros(): void
    {
        Schema::create('aplicaciones_ordenes_emergencia', function (Blueprint $table): void {
            $table->integer('id');
        });
        DB::table('aplicaciones_ordenes_emergencia')->insert(['id' => 1]);
        $migracion = require database_path('migrations/2026_10_21_204409_consolidate_shared_data_and_remove_unused_tables.php');
        try {
            $migracion->up();
            $this->fail('Debe impedir la pérdida de datos pendientes.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('No se elimina aplicaciones_ordenes_emergencia', $e->getMessage());
        }
        $this->assertDatabaseHas('aplicaciones_ordenes_emergencia', ['id' => 1]);
        $this->assertTrue(Schema::hasTable('pacientes'));
    }

    public function test_no_elimina_infraestructura_si_la_configuracion_la_sigue_utilizando(): void
    {
        config(['cache.default' => 'database']);
        $migracion = require database_path('migrations/2026_10_21_204409_consolidate_shared_data_and_remove_unused_tables.php');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sesiones y caché en archivos');
        $migracion->up();
    }
}
