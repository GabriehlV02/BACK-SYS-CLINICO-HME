<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class IntegracionContableTest extends TestCase
{
    use RefreshDatabase;

    private function central(): array
    {
        config(['database.connections.usuarios' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true], 'cache.default' => 'array']);
        DB::purge('usuarios');
        $schema = Schema::connection('usuarios');
        $schema->create('usuarios_sistema', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('usuario')->unique();
            $t->string('password');
            $t->string('nombres');
            $t->string('apellidos')->nullable();
            $t->string('correo')->nullable();
            $t->string('ci')->nullable();
            $t->string('tipo_usuario');
            $t->string('estado');
            $t->string('rol')->nullable();
            $t->json('cajas')->nullable();
            $t->timestamps();
        });
        $schema->create('sistemas', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('codigo');
            $t->boolean('activo');
        });
        $schema->create('roles', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('codigo');
            $t->string('nombre');
            $t->string('descripcion')->nullable();
            $t->boolean('activo');
            $t->timestamps();
        });
        $schema->create('rol_usuario', function (Blueprint $t): void {
            $t->uuid('usuario_id');
            $t->uuid('rol_id');
        });
        $schema->create('rol_sistema', function (Blueprint $t): void {
            $t->uuid('rol_id');
            $t->uuid('sistema_id');
        });
        $schema->create('permisos', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('sistema_id');
            $t->string('codigo');
            $t->boolean('activo');
        });
        $schema->create('permiso_rol', function (Blueprint $t): void {
            $t->uuid('rol_id');
            $t->uuid('permiso_id');
        });
        $db = DB::connection('usuarios');
        $sistema = (string) Str::uuid();
        $rol = (string) Str::uuid();
        $usuario = (string) Str::uuid();
        $db->table('sistemas')->insert(['id' => $sistema, 'codigo' => 'CLINICO', 'activo' => true]);
        $db->table('roles')->insert(['id' => $rol, 'codigo' => 'ADMINISTRADOR', 'nombre' => 'Administrador común', 'activo' => true]);
        $db->table('rol_sistema')->insert(['rol_id' => $rol, 'sistema_id' => $sistema]);
        $db->table('usuarios_sistema')->insert(['id' => $usuario, 'usuario' => 'central', 'password' => Hash::make('ClaveCompartida1'), 'nombres' => 'Cuenta central', 'apellidos' => 'Compartida', 'tipo_usuario' => 'EMPRESA', 'estado' => 'ACTIVO']);
        $db->table('rol_usuario')->insert(['usuario_id' => $usuario, 'rol_id' => $rol]);
        foreach (['USUARIOS_VER', 'USUARIOS_CREAR', 'ROLES_GESTIONAR', 'CONSULTAS_ATENDER', 'INVENTARIO_GESTIONAR'] as $codigo) {
            $id = (string) Str::uuid();
            $db->table('permisos')->insert(['id' => $id, 'sistema_id' => $sistema, 'codigo' => $codigo, 'activo' => true]);
            $db->table('permiso_rol')->insert(['rol_id' => $rol, 'permiso_id' => $id]);
        }

        return compact('usuario', 'rol', 'sistema');
    }

    private function login(): string
    {
        return $this->postJson('/api/login', ['usuario' => 'central', 'password' => 'ClaveCompartida1'])->assertOk()->json('token');
    }

    public function test_cuenta_central_inicia_sesion_y_lista_los_mismos_usuarios(): void
    {
        $ids = $this->central();
        $this->postJson('/api/login', ['usuario' => 'central', 'password' => 'incorrecta'])->assertUnauthorized();
        $token = $this->login();
        $this->withToken($token)->getJson('/api/session')->assertOk()->assertJsonPath('usuario.id', $ids['usuario'])->assertJsonMissingPath('usuario.password');
        $this->withToken($token)->getJson('/api/v1/usuarios')->assertOk()->assertJsonPath('data.0.id', $ids['usuario'])->assertJsonMissingPath('data.0.password');
        $this->withToken($token)->getJson('/api/v1/roles')->assertOk()->assertJsonPath('data.0.id', $ids['rol']);
    }

    public function test_no_acepta_sesion_ausente_falsa_expirada_o_revocada(): void
    {
        $this->central();
        $this->getJson('/api/session')->assertUnauthorized();
        $this->getJson('/api/emergencias')->assertUnauthorized();
        $this->postJson('/api/v1/pacientes', [])->assertUnauthorized();
        $this->withToken('token-inventado')->getJson('/api/session')->assertUnauthorized();
        $token = $this->login();
        $this->withToken($token)->postJson('/api/logout')->assertNoContent();
        $this->withToken($token)->getJson('/api/session')->assertUnauthorized();
        $token = $this->login();
        $this->travel(9)->hours();
        $this->withToken($token)->getJson('/api/session')->assertUnauthorized();
    }

    public function test_desactivar_la_cuenta_central_o_su_rol_invalida_el_acceso(): void
    {
        $ids = $this->central();
        $token = $this->login();
        DB::connection('usuarios')->table('usuarios_sistema')->where('id', $ids['usuario'])->update(['estado' => 'INACTIVO']);
        $this->withToken($token)->getJson('/api/session')->assertUnauthorized();
        $this->postJson('/api/login', ['usuario' => 'central', 'password' => 'ClaveCompartida1'])->assertUnauthorized();
        DB::connection('usuarios')->table('usuarios_sistema')->where('id', $ids['usuario'])->update(['estado' => 'ACTIVO']);
        DB::connection('usuarios')->table('roles')->where('id', $ids['rol'])->update(['activo' => false]);
        $this->withToken($token)->getJson('/api/session')->assertUnauthorized();
    }

    public function test_cuenta_sin_acceso_clinico_o_de_paciente_no_puede_entrar(): void
    {
        $ids = $this->central();
        DB::connection('usuarios')->table('sistemas')->where('id', $ids['sistema'])->update(['codigo' => 'CONTABLE']);
        $this->postJson('/api/login', ['usuario' => 'central', 'password' => 'ClaveCompartida1'])->assertUnauthorized();
        DB::connection('usuarios')->table('sistemas')->where('id', $ids['sistema'])->update(['codigo' => 'CLINICO']);
        DB::connection('usuarios')->table('usuarios_sistema')->where('id', $ids['usuario'])->update(['tipo_usuario' => 'PACIENTE']);
        $this->postJson('/api/login', ['usuario' => 'central', 'password' => 'ClaveCompartida1'])->assertUnauthorized();
    }

    public function test_cambio_de_clave_modifica_la_cuenta_central_y_revoca_tokens_previos(): void
    {
        $ids = $this->central();
        $token = $this->login();
        $this->withToken($token)->postJson('/api/cambiar-contrasena', ['usuario' => 'central', 'contrasena_actual' => 'ClaveCompartida1', 'contrasena_nueva' => 'NuevaCompartida2', 'confirmar_contrasena' => 'NuevaCompartida2'])->assertOk();
        $this->assertTrue(Hash::check('NuevaCompartida2', DB::connection('usuarios')->table('usuarios_sistema')->where('id', $ids['usuario'])->value('password')));
        $this->withToken($token)->getJson('/api/session')->assertUnauthorized();
        $this->postJson('/api/login', ['usuario' => 'central', 'password' => 'NuevaCompartida2'])->assertOk();
    }

    public function test_no_permite_administrar_usuarios_sin_permiso_ni_alterar_admin(): void
    {
        $this->central();
        $token = $this->login();
        $this->withToken($token)->putJson('/api/v1/roles/'.DB::connection('usuarios')->table('roles')->value('id'), ['nombre' => 'Alterado', 'permisos' => []])->assertUnprocessable();
        DB::connection('usuarios')->table('permisos')->where('codigo', 'USUARIOS_VER')->update(['activo' => false]);
        $this->withToken($token)->getJson('/api/v1/usuarios')->assertForbidden();
    }

    public function test_usuario_creado_en_clinico_se_guarda_solo_en_la_fuente_contable(): void
    {
        $ids = $this->central();
        $token = $this->login();
        $datos = ['tipo_usuario' => 'EMPRESA', 'nombres' => 'Compartido', 'apellido_paterno' => 'Nuevo', 'apellido_materno' => 'Usuario', 'ci' => '123456', 'correo' => 'nuevo@example.test', 'usuario' => 'nuevo', 'password' => 'NuevaClave123', 'rol' => $ids['rol'], 'caja' => 'Caja 01'];
        $this->withToken($token)->postJson('/api/v1/usuarios', $datos)->assertCreated()->assertJsonPath('data.apellidos', 'Nuevo Usuario')->assertJsonMissingPath('data.password');
        $this->assertSame(2, DB::connection('usuarios')->table('usuarios_sistema')->count());
        $this->withToken($token)->postJson('/api/v1/usuarios', $datos)->assertUnprocessable()->assertJsonValidationErrors('usuario');
        $this->postJson('/api/login', ['usuario' => 'nuevo', 'password' => 'NuevaClave123'])->assertOk();
    }

    public function test_catalogo_lee_productos_servicios_y_stock_real_sin_copiar_registros(): void
    {
        $this->central();
        $schema = Schema::connection('usuarios');
        foreach (['tipos_inventario', 'unidades_inventario', 'categorias_inventario'] as $tabla) {
            $schema->create($tabla, function (Blueprint $t): void {
                $t->integer('id')->primary();
                $t->string('nombre');
                $t->string('tipo_base')->nullable();
                $t->boolean('activo')->default(true);
            });
        }
        $schema->create('productos_base', function (Blueprint $t): void {
            $t->integer('id')->primary();
            $t->string('nombre');
            $t->integer('tipo_id');
            $t->integer('categoria_id');
            $t->string('estado');
        });
        $schema->create('items_inventario', function (Blueprint $t): void {
            $t->integer('id')->primary();
            $t->integer('producto_base_id');
            $t->integer('unidad_id');
            $t->string('nombre_comercial');
            $t->string('marca');
            $t->string('presentacion');
            $t->decimal('precio_1', 12, 2);
            $t->decimal('precio_2', 12, 2);
            $t->decimal('precio_3', 12, 2);
            $t->string('estado');
        });
        $schema->create('lotes_items', function (Blueprint $t): void {
            $t->integer('id')->primary();
            $t->integer('item_id');
        });
        $schema->create('existencias_lote', function (Blueprint $t): void {
            $t->integer('id')->primary();
            $t->integer('lote_id');
            $t->decimal('cantidad', 12, 3);
        });
        $schema->create('servicios', function (Blueprint $t): void {
            $t->integer('id')->primary();
            $t->string('nombre');
            $t->integer('tipo_id');
            $t->integer('categoria_id');
            $t->integer('unidad_id');
            $t->decimal('precio_venta', 12, 2);
            $t->string('estado');
        });
        $db = DB::connection('usuarios');
        $db->table('tipos_inventario')->insert([['id' => 1, 'nombre' => 'Producto', 'tipo_base' => 'PRODUCTO'], ['id' => 2, 'nombre' => 'Servicio', 'tipo_base' => 'SERVICIO']]);
        $db->table('unidades_inventario')->insert(['id' => 1, 'nombre' => 'Unidad']);
        $db->table('categorias_inventario')->insert(['id' => 1, 'nombre' => 'Medicamentos']);
        $db->table('productos_base')->insert(['id' => 1, 'nombre' => 'Paracetamol', 'tipo_id' => 1, 'categoria_id' => 1, 'estado' => 'ACTIVO']);
        $db->table('items_inventario')->insert(['id' => 1, 'producto_base_id' => 1, 'unidad_id' => 1, 'nombre_comercial' => 'Comercial real', 'marca' => 'Marca real', 'presentacion' => '500 mg', 'precio_1' => 12.5, 'precio_2' => 14, 'precio_3' => 16, 'estado' => 'ACTIVO']);
        $db->table('lotes_items')->insert(['id' => 1, 'item_id' => 1]);
        $db->table('existencias_lote')->insert([['id' => 1, 'lote_id' => 1, 'cantidad' => 2.125], ['id' => 2, 'lote_id' => 1, 'cantidad' => 3.25]]);
        $db->table('servicios')->insert(['id' => 1, 'nombre' => 'Consulta real', 'tipo_id' => 2, 'categoria_id' => 1, 'unidad_id' => 1, 'precio_venta' => 55, 'estado' => 'ACTIVO']);
        $this->getJson('/api/catalogo')->assertUnauthorized();
        $token = $this->login();
        $this->withToken($token)->getJson('/api/catalogo')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', 'item-1')->assertJsonPath('data.0.precio', 12.5)->assertJsonPath('data.0.stock', 5.375)->assertJsonPath('data.1.id', 'servicio-1')->assertJsonPath('data.1.precio', 55);
        $db->table('items_inventario')->where('id', 1)->update(['nombre_comercial' => 'Nombre actualizado']);
        $this->withToken($token)->getJson('/api/emergencias/catalogo')->assertOk()->assertJsonPath('0.nombre', 'Nombre actualizado');
        $db->table('servicios')->where('id', 1)->update(['estado' => 'INACTIVO']);
        $this->withToken($token)->getJson('/api/catalogo')->assertJsonCount(1, 'data');
        $this->withToken($token)->getJson('/api/v1/farmacia/productos')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 'item-1');
        $this->withToken($token)->postJson('/api/v1/farmacia/productos', [])->assertConflict();
        $this->assertSame(1, $db->table('items_inventario')->count());
    }

    public function test_asigna_profesionales_centrales_sin_recrear_usuarios_locales_y_traslada_una_sola_vez(): void
    {
        $ids = $this->central();
        $token = $this->login();
        $atencion = (string) Str::uuid();
        DB::table('atenciones')->insert(['id' => $atencion, 'tipo' => 'emergencia', 'estado' => 'en_curso', 'iniciada_en' => now()]);
        $datos = ['area' => 'EMERGENCIA', 'profesionales' => [['usuario_id' => $ids['usuario'], 'rol' => 'RESPONSABLE']]];
        $this->withToken($token)->postJson("/api/v1/atenciones/{$atencion}/estancias", [...$datos, 'profesionales' => [['usuario_id' => (string) Str::uuid(), 'rol' => 'RESPONSABLE']]])
            ->assertUnprocessable()->assertJsonValidationErrors('profesionales.0.usuario_id');
        $id = $this->withToken($token)->postJson("/api/v1/atenciones/{$atencion}/estancias", $datos)->assertCreated()->json('data.id');
        $this->assertDatabaseHas('estancia_profesionales', ['estancia_atencion_id' => $id, 'usuario_id' => $ids['usuario']]);
        $this->withToken($token)->postJson("/api/v1/atenciones/{$atencion}/estancias", $datos)->assertUnprocessable();
        $destino = $this->withToken($token)->postJson("/api/v1/atenciones/{$atencion}/estancias/{$id}/traslado", [...$datos, 'area' => 'INTERNACION'])
            ->assertCreated()->json('data.id');
        $this->assertDatabaseHas('estancias_atencion', ['id' => $id, 'estado' => 'FINALIZADA']);
        $this->assertDatabaseHas('estancias_atencion', ['id' => $destino, 'estado' => 'ACTIVA']);
        $this->withToken($token)->postJson("/api/v1/atenciones/{$atencion}/estancias/{$id}/traslado", ['area' => 'INTERNACION'])->assertNotFound();
        $this->assertFalse(Schema::hasTable('usuarios_sistema'));
    }

    public function test_no_asigna_pacientes_o_usuarios_inactivos_como_profesionales(): void
    {
        $ids = $this->central();
        $token = $this->login();
        $paciente = (string) Str::uuid();
        DB::connection('usuarios')->table('usuarios_sistema')->insert(['id' => $paciente, 'usuario' => 'portal', 'password' => 'hash', 'nombres' => 'Paciente', 'tipo_usuario' => 'PACIENTE', 'estado' => 'ACTIVO']);
        $atencion = (string) Str::uuid();
        DB::table('atenciones')->insert(['id' => $atencion, 'tipo' => 'emergencia', 'estado' => 'en_curso', 'iniciada_en' => now()]);
        $this->withToken($token)->postJson("/api/v1/atenciones/{$atencion}/estancias", ['area' => 'EMERGENCIA', 'profesionales' => [['usuario_id' => $paciente, 'rol' => 'RESPONSABLE']]])
            ->assertUnprocessable()->assertJsonValidationErrors('profesionales.0.usuario_id');
        DB::connection('usuarios')->table('usuarios_sistema')->where('id', $paciente)->update(['tipo_usuario' => 'EMPRESA', 'estado' => 'INACTIVO']);
        $this->withToken($token)->postJson("/api/v1/atenciones/{$atencion}/estancias", ['area' => 'EMERGENCIA', 'profesionales' => [['usuario_id' => $paciente, 'rol' => 'RESPONSABLE']]])
            ->assertUnprocessable()->assertJsonValidationErrors('profesionales.0.usuario_id');
        $this->assertDatabaseCount('estancias_atencion', 0);
    }

    public function test_comparte_almacenes_y_los_vincula_a_cajas_con_identificadores_contables(): void
    {
        $ids = $this->central();
        $schema = Schema::connection('usuarios');
        $schema->create('sucursales_inventario', function (Blueprint $t): void {
            $t->id();
            $t->string('nombre');
            $t->string('codigo')->unique();
            $t->boolean('activo')->default(true);
            $t->timestamps();
        });
        $schema->create('almacenes_inventario', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('sucursal_id')->constrained('sucursales_inventario');
            $t->string('nombre');
            $t->string('codigo')->unique();
            $t->boolean('activo')->default(true);
            $t->timestamps();
        });
        $token = $this->login();
        $almacen = $this->withToken($token)->postJson('/api/v1/operacion/almacenes', ['nombre' => 'Farmacia', 'codigo' => 'FAR-01', 'sucursal' => 'Central'])
            ->assertCreated()->json('data.id');
        $this->assertSame('1', $almacen);
        $this->assertDatabaseHas('almacenes_inventario', ['id' => 1, 'nombre' => 'Farmacia'], 'usuarios');
        $this->withToken($token)->postJson('/api/v1/operacion/almacenes', ['nombre' => 'Otro', 'codigo' => 'FAR-01', 'sucursal' => 'Central'])->assertUnprocessable();
        $caja = $this->withToken($token)->postJson('/api/v1/operacion/cajas', ['nombre' => 'Caja 1', 'sucursal' => 'Central', 'almacen_principal_id' => $almacen, 'almacenes' => [$almacen, $almacen]])
            ->assertCreated()->json('data.id');
        $this->assertDatabaseHas('cajas', ['id' => $caja, 'almacen_principal_id' => 1]);
        $this->assertDatabaseCount('caja_almacen', 1);
        $this->withToken($token)->getJson('/api/v1/operacion/almacenes-cajas')->assertOk()->assertJsonPath('cajas.0.almacenes.0.id', '1');
        $this->withToken($token)->patchJson('/api/v1/operacion/almacenes/1/estado')->assertOk()->assertJsonPath('estado', 'INACTIVO');
        $this->assertDatabaseHas('almacenes_inventario', ['id' => 1, 'activo' => false], 'usuarios');
        $this->withToken($token)->postJson('/api/v1/operacion/cajas', ['nombre' => 'Caja 2', 'sucursal' => 'Central', 'almacen_principal_id' => 1])->assertUnprocessable();
        $permiso = DB::connection('usuarios')->table('permisos')->where('codigo', 'INVENTARIO_GESTIONAR')->value('id');
        DB::connection('usuarios')->table('permiso_rol')->where('permiso_id', $permiso)->delete();
        $this->withToken($token)->patchJson('/api/v1/operacion/almacenes/1/estado')->assertForbidden();
        $this->assertFalse(Schema::hasTable('almacenes'));
    }

    public function test_no_permite_escalar_privilegios_al_asignar_roles_compartidos(): void
    {
        $ids = $this->central();
        $token = $this->login();
        $db = DB::connection('usuarios');
        $extra = (string) Str::uuid();
        $rol = (string) Str::uuid();
        $db->table('permisos')->insert(['id' => $extra, 'sistema_id' => $ids['sistema'], 'codigo' => 'PERMISO_NO_ASIGNADO', 'activo' => true]);
        $db->table('roles')->insert(['id' => $rol, 'codigo' => 'ESPECIAL', 'nombre' => 'Especial', 'activo' => true]);
        $db->table('permiso_rol')->insert(['rol_id' => $rol, 'permiso_id' => $extra]);
        $this->withToken($token)->postJson('/api/v1/roles', ['nombre' => 'Escalado', 'permisos' => ['PERMISO_NO_ASIGNADO']])->assertUnprocessable()->assertJsonValidationErrors('permisos');
        $this->withToken($token)->postJson('/api/v1/usuarios', ['tipo_usuario' => 'EMPRESA', 'nombres' => 'Escalado', 'apellido_paterno' => 'Prueba', 'ci' => '999', 'correo' => 'escalado@example.test', 'usuario' => 'escalado', 'password' => 'NoPermitir123', 'rol' => $rol, 'caja' => 'Caja 01'])->assertUnprocessable()->assertJsonValidationErrors('rol');
        $this->assertSame(1, $db->table('usuarios_sistema')->count());
        $this->assertSame(2, $db->table('roles')->count());
    }
}
