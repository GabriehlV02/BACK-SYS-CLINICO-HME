<?php

namespace Tests\Feature;

use App\Flows\Autenticacion\AccesoCompartido;
use App\Http\Middleware\RegistrarAuditoriaAccion;
use App\Http\Middleware\VerificarSesionCompartida;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InsumosEnfermeriaTest extends TestCase
{
    private const URL = '/api/v1/enfermeria/insumos';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.usuarios' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('usuarios');
        $schema = Schema::connection('usuarios');
        $schema->create('sucursales_inventario', function (Blueprint $t): void {
            $t->id();
            $t->string('nombre');
            $t->boolean('activo');
        });
        $schema->create('almacenes_inventario', function (Blueprint $t): void {
            $t->id();
            $t->integer('sucursal_id');
            $t->string('nombre');
            $t->boolean('activo');
        });
        $schema->create('tipos_inventario', function (Blueprint $t): void {
            $t->id();
            $t->string('tipo_base');
            $t->boolean('activo');
        });
        $schema->create('unidades_inventario', function (Blueprint $t): void {
            $t->id();
            $t->string('nombre');
            $t->boolean('activo');
        });
        $schema->create('productos_base', function (Blueprint $t): void {
            $t->id();
            $t->integer('tipo_id');
            $t->string('estado');
        });
        $schema->create('items_inventario', function (Blueprint $t): void {
            $t->id();
            $t->integer('producto_base_id');
            $t->integer('unidad_id');
            $t->string('nombre_comercial');
            $t->string('clave');
            $t->string('estado');
        });
        $schema->create('lotes_items', function (Blueprint $t): void {
            $t->id();
            $t->integer('item_id');
            $t->date('vencimiento')->nullable();
        });
        $schema->create('existencias_lote', function (Blueprint $t): void {
            $t->id();
            $t->integer('lote_id');
            $t->integer('almacen_id');
            $t->decimal('cantidad', 18, 3);
            $t->decimal('costo_unitario')->nullable();
            $t->date('fecha_ingreso')->nullable();
            $t->timestamps();
            $t->unique(['lote_id', 'almacen_id']);
        });
        $schema->create('clinico_solicitudes_insumos', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('solicitado_por');
            $t->string('solicitante');
            $t->integer('almacen_destino_id');
            $t->string('estado');
            $t->json('detalles');
            $t->string('observacion')->nullable();
            $t->integer('almacen_origen_id')->nullable();
            $t->uuid('dispensado_por')->nullable();
            $t->timestamp('dispensado_en')->nullable();
            $t->json('movimientos')->nullable();
            $t->timestamps();
        });
        $db = DB::connection('usuarios');
        $db->table('sucursales_inventario')->insert(['id' => 1, 'nombre' => 'Hospital', 'activo' => true]);
        foreach ([1 => 'Farmacia', 2 => 'Enfermeria', 3 => 'Otro almacen'] as $id => $nombre) {
            $db->table('almacenes_inventario')->insert(['id' => $id, 'sucursal_id' => 1, 'nombre' => $nombre, 'activo' => true]);
        }
        $db->table('tipos_inventario')->insert(['id' => 1, 'tipo_base' => 'INSUMO', 'activo' => true]);
        $db->table('unidades_inventario')->insert(['id' => 1, 'nombre' => 'Unidad', 'activo' => true]);
        $db->table('productos_base')->insert(['id' => 1, 'tipo_id' => 1, 'estado' => 'ACTIVO']);
        foreach ([1 => 'Jeringa', 2 => 'Gasa'] as $id => $nombre) {
            $db->table('items_inventario')->insert(['id' => $id, 'producto_base_id' => 1, 'unidad_id' => 1, 'nombre_comercial' => $nombre, 'clave' => 'INS-'.$id, 'estado' => 'ACTIVO']);
        }
        $db->table('lotes_items')->insert([['id' => 1, 'item_id' => 1, 'vencimiento' => '2099-01-01'], ['id' => 2, 'item_id' => 1, 'vencimiento' => '2000-01-01']]);
        $db->table('existencias_lote')->insert([
            ['lote_id' => 1, 'almacen_id' => 1, 'cantidad' => 5], ['lote_id' => 1, 'almacen_id' => 2, 'cantidad' => 2], ['lote_id' => 2, 'almacen_id' => 1, 'cantidad' => 100],
        ]);
        $this->withoutMiddleware([VerificarSesionCompartida::class, RegistrarAuditoriaAccion::class]);
        $this->actingAs(new GenericUser(['id' => '11111111-1111-4111-8111-111111111111', 'nombres' => 'Enfermeria']));
        $this->acceso([2]);
    }

    private function acceso(array $almacenes, bool $farmacia = false): void
    {
        $acceso = $this->mock(AccesoCompartido::class);
        $acceso->shouldReceive('conexion')->andReturn(DB::connection('usuarios'));
        $acceso->shouldReceive('almacenesPermitidos')->andReturn($almacenes);
        $acceso->shouldReceive('esAdministrador')->andReturn(false);
        $acceso->shouldReceive('exigir')->andReturnUsing(function () use ($farmacia): void {
            abort_unless($farmacia, 403);
        });
    }

    private function pedido(float $cantidad = 4): array
    {
        return ['id' => '22222222-2222-4222-8222-222222222222', 'almacen_destino_id' => 2, 'detalles' => [['item_id' => 1, 'cantidad' => $cantidad]], 'observacion' => 'Reposicion'];
    }

    public function test_lista_solo_el_stock_del_almacen_asignado_y_permite_solicitar_insumos_ausentes(): void
    {
        $this->getJson(self::URL.'/opciones')->assertOk()->assertJsonCount(1, 'almacenes')->assertJsonCount(2, 'catalogo');
        $this->getJson(self::URL.'/stock?almacen_id=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nombre', 'Jeringa')->assertJsonPath('data.0.cantidad', 2);
        $this->getJson(self::URL.'/stock?almacen_id=1')->assertForbidden();
        $this->postJson(self::URL.'/solicitudes', [...$this->pedido(), 'detalles' => [['item_id' => 2, 'cantidad' => 3]]])->assertCreated();
        $this->assertDatabaseHas('existencias_lote', ['almacen_id' => 2, 'cantidad' => 2], 'usuarios');
    }

    public function test_solicitar_no_aumenta_stock_y_dispensar_lo_transfiere_sin_duplicarlo(): void
    {
        $pedido = $this->pedido();
        $this->postJson(self::URL.'/solicitudes', $pedido)->assertCreated();
        $this->postJson(self::URL.'/solicitudes', $pedido)->assertCreated();
        $this->assertDatabaseCount('clinico_solicitudes_insumos', 1, 'usuarios');
        $this->assertDatabaseHas('existencias_lote', ['lote_id' => 1, 'almacen_id' => 2, 'cantidad' => 2], 'usuarios');
        $this->acceso([1], true);
        $this->getJson(self::URL.'/solicitudes?farmacia=1')->assertOk()->assertJsonPath('data.0.estado', 'pendiente');
        $url = self::URL.'/solicitudes/'.$pedido['id'].'/dispensar';
        $this->postJson($url, ['almacen_origen_id' => 1])->assertOk();
        $this->postJson($url, ['almacen_origen_id' => 1])->assertOk();
        $this->assertDatabaseHas('existencias_lote', ['lote_id' => 1, 'almacen_id' => 1, 'cantidad' => 1], 'usuarios');
        $this->assertDatabaseHas('existencias_lote', ['lote_id' => 1, 'almacen_id' => 2, 'cantidad' => 6], 'usuarios');
        $this->assertDatabaseHas('clinico_solicitudes_insumos', ['id' => $pedido['id'], 'estado' => 'dispensada'], 'usuarios');
    }

    public function test_stock_insuficiente_o_vencido_no_mueve_existencias(): void
    {
        $pedido = $this->pedido(6);
        $this->postJson(self::URL.'/solicitudes', $pedido)->assertCreated();
        $this->acceso([1], true);
        $this->postJson(self::URL.'/solicitudes/'.$pedido['id'].'/dispensar', ['almacen_origen_id' => 1])->assertUnprocessable()->assertJsonValidationErrors('stock');
        $this->assertDatabaseHas('existencias_lote', ['lote_id' => 1, 'almacen_id' => 1, 'cantidad' => 5], 'usuarios');
        $this->assertDatabaseHas('existencias_lote', ['lote_id' => 1, 'almacen_id' => 2, 'cantidad' => 2], 'usuarios');
        $this->assertDatabaseHas('clinico_solicitudes_insumos', ['id' => $pedido['id'], 'estado' => 'pendiente'], 'usuarios');
    }

    public function test_dispensar_un_insumo_ausente_crea_su_existencia_en_destino(): void
    {
        DB::connection('usuarios')->table('lotes_items')->insert(['id' => 3, 'item_id' => 2, 'vencimiento' => null]);
        DB::connection('usuarios')->table('existencias_lote')->insert(['lote_id' => 3, 'almacen_id' => 1, 'cantidad' => 9, 'costo_unitario' => 1.25]);
        $pedido = [...$this->pedido(), 'detalles' => [['item_id' => 2, 'cantidad' => 3]]];
        $this->postJson(self::URL.'/solicitudes', $pedido)->assertCreated();
        $this->acceso([1], true);
        $this->postJson(self::URL.'/solicitudes/'.$pedido['id'].'/dispensar', ['almacen_origen_id' => 1])->assertOk();
        $this->assertDatabaseHas('existencias_lote', ['lote_id' => 3, 'almacen_id' => 1, 'cantidad' => 6], 'usuarios');
        $this->assertDatabaseHas('existencias_lote', ['lote_id' => 3, 'almacen_id' => 2, 'cantidad' => 3, 'costo_unitario' => 1.25], 'usuarios');
        $this->acceso([2]);
        $this->getJson(self::URL.'/stock?almacen_id=2')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_no_dispensa_desde_un_almacen_ajeno_o_al_mismo_destino(): void
    {
        $pedido = $this->pedido();
        $this->postJson(self::URL.'/solicitudes', $pedido)->assertCreated();
        $this->acceso([2], true);
        $url = self::URL.'/solicitudes/'.$pedido['id'].'/dispensar';
        $this->postJson($url, ['almacen_origen_id' => 1])->assertForbidden();
        $this->postJson($url, ['almacen_origen_id' => 2])->assertUnprocessable();
        $this->assertDatabaseHas('clinico_solicitudes_insumos', ['id' => $pedido['id'], 'estado' => 'pendiente'], 'usuarios');
    }

    public function test_si_falta_otro_insumo_se_revierte_la_dispensacion_completa(): void
    {
        $pedido = $this->pedido();
        $pedido['detalles'][] = ['item_id' => 2, 'cantidad' => 1];
        $this->postJson(self::URL.'/solicitudes', $pedido)->assertCreated();
        $this->acceso([1], true);
        $this->postJson(self::URL.'/solicitudes/'.$pedido['id'].'/dispensar', ['almacen_origen_id' => 1])->assertUnprocessable();
        $this->assertDatabaseHas('existencias_lote', ['lote_id' => 1, 'almacen_id' => 1, 'cantidad' => 5], 'usuarios');
        $this->assertDatabaseHas('existencias_lote', ['lote_id' => 1, 'almacen_id' => 2, 'cantidad' => 2], 'usuarios');
    }

    public function test_enfermeria_no_dispensa_y_no_solicita_para_almacenes_ajenos(): void
    {
        $pedido = $this->pedido();
        $this->postJson(self::URL.'/solicitudes', $pedido)->assertCreated();
        $this->postJson(self::URL.'/solicitudes/'.$pedido['id'].'/dispensar', ['almacen_origen_id' => 1])->assertForbidden();
        $this->getJson(self::URL.'/solicitudes?farmacia=1')->assertForbidden();
        $this->postJson(self::URL.'/solicitudes', [...$pedido, 'almacen_destino_id' => 3])->assertForbidden();
        $this->getJson(self::URL.'/solicitudes')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_valida_cantidades_catalogo_y_conflictos_de_solicitud(): void
    {
        $this->postJson(self::URL.'/solicitudes', $this->pedido(0))->assertUnprocessable();
        $this->postJson(self::URL.'/solicitudes', [...$this->pedido(), 'detalles' => [['item_id' => 999, 'cantidad' => 1]]])->assertUnprocessable();
        $this->postJson(self::URL.'/solicitudes', $this->pedido())->assertCreated();
        $this->postJson(self::URL.'/solicitudes', $this->pedido(3))->assertStatus(409);
        $this->assertDatabaseCount('clinico_solicitudes_insumos', 1, 'usuarios');
    }
}
