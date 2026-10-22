<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (config('session.driver') === 'database' || config('cache.default') === 'database' || config('queue.default') === 'database') {
            throw new RuntimeException('Configura sesiones y caché en archivos y QUEUE_CONNECTION=sync antes de limpiar las tablas técnicas locales.');
        }
        $tablas = [
            'aplicaciones_ordenes_emergencia', 'ordenes_emergencia', 'evaluaciones_emergencia',
            'signos_vitales_emergencia', 'pagos_emergencia', 'consumos_emergencia', 'cuentas_emergencia',
            'consultas', 'medico_especialidad', 'medicos', 'consultorios', 'especialidades',
            'historias_clinicas', 'farmacia_almacen_items', 'farmacia_movimientos', 'farmacia_productos',
            'almacen_existencias', 'almacenes', 'caja_almacen', 'cajas',
            'users', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        ];
        $accesosLocales = ['permiso_rol', 'rol_usuario', 'rol_sistema', 'permisos', 'roles', 'sistemas', 'usuarios_sistema', 'tipos_usuarios'];
        if (DB::getDriverName() === 'pgsql') {
            $existentes = array_filter([...$tablas, ...$accesosLocales, 'atenciones', 'estancia_profesionales'], fn (string $tabla): bool => Schema::hasTable($tabla));
            DB::statement('LOCK TABLE '.implode(', ', $existentes).' IN ACCESS EXCLUSIVE MODE');
        }
        foreach ($tablas as $tabla) {
            if (DB::table($tabla)->exists()) {
                throw new RuntimeException("No se elimina {$tabla}: contiene datos que necesitan migrarse antes de retirar la estructura local.");
            }
        }
        if (DB::table('estancia_profesionales')->whereNotNull('medico_id')->exists()
            || DB::table('estancia_profesionales')->whereNotNull('usuario_id')->exists()
            || DB::table('atenciones')->whereNotNull('historia_clinica_id')->exists()) {
            throw new RuntimeException('Hay relaciones clínicas históricas que deben migrarse antes de limpiar el esquema.');
        }
        foreach (DB::table('usuarios_sistema')->select('usuario')->cursor() as $usuario) {
            if (! DB::connection('usuarios')->table('usuarios_sistema')->where('usuario', $usuario->usuario)->exists()) {
                throw new RuntimeException("El usuario local {$usuario->usuario} no existe en la base compartida; migra su cuenta antes de retirar los accesos locales.");
            }
        }
        // PostgreSQL no admite claves foráneas entre bases: los UUID centrales se validan al asignar profesionales.
        Schema::table('estancia_profesionales', function (Blueprint $table): void {
            $table->dropForeign(['usuario_id']);
            $table->dropForeign(['medico_id']);
            $table->dropIndex(['medico_id']);
            $table->dropColumn('medico_id');
        });
        Schema::table('atenciones', function (Blueprint $table): void {
            $table->dropForeign(['historia_clinica_id']);
            $table->dropIndex(['historia_clinica_id']);
            $table->dropColumn('historia_clinica_id');
        });
        // Retirar primero las dependencias locales de almacenes.
        Schema::drop('caja_almacen');
        Schema::drop('cajas');
        foreach (array_diff($tablas, ['caja_almacen', 'cajas']) as $tabla) {
            Schema::drop($tabla);
        }
        // Estas tablas son copias de los accesos; la fuente vigente está en la conexión usuarios.
        foreach ($accesosLocales as $tabla) {
            Schema::drop($tabla);
        }
        Schema::create('cajas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('nombre', 120);
            $table->string('sucursal', 120);
            $table->unsignedBigInteger('almacen_principal_id');
            $table->json('tipos_item_permitidos')->nullable();
            $table->unsignedInteger('limite_items')->nullable();
            $table->string('estado', 15)->default('ACTIVO');
            $table->timestamps();
        });
        Schema::create('caja_almacen', function (Blueprint $table): void {
            $table->uuid('caja_id');
            $table->unsignedBigInteger('almacen_id');
            $table->primary(['caja_id', 'almacen_id']);
            $table->foreign('caja_id')->references('id')->on('cajas')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Esta limpieza retira copias de accesos y estructuras sin uso. Para recuperar el esquema anterior, restaura el respaldo previo a la limpieza.');
    }
};
